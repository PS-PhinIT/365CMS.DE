# 365CMS – Projektdokumentation | Abschnitt: Admin – Backup & Restore

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/backups` (Alt-Route `/admin/backup` leitet um) | **Capability:** `manage_settings` oder `manage_system` | **CSRF-Aktion:** `admin_backups`

## English (summary)

Backups are created, validated, downloaded, restored and deleted at `/admin/backups` (`CMS/admin/backups.php` → `CMS/admin/modules/system/BackupsModule.php` → `CMS\Services\BackupService`, view `CMS/admin/views/system/backups.php`). Backups are stored in `CMS/backups/` or, since 4.0.0, in the directory set by `CMS_BACKUP_PATH` in `config/app.php` (recommended: outside the web root), one directory per backup with a `manifest.json` (SHA-256 hashes). Since 4.0.0 new backups can be encrypted (XChaCha20-Poly1305, key `CMS_ENCRYPTION_KEY`); encrypted parts end in `.enc` and are decrypted only temporarily for validation and restore. A **full backup** contains a database dump plus a ZIP of `uploads/`, `themes/` and `plugins/` (since 4.0.0 without the core-owned `assets/`); a **database backup** contains a (gzip-compressed) SQL dump. Older single-file dumps `database_*.sql[.gz]` are still listed. Backups without a valid manifest or with missing files are shown as *incomplete*, cannot be restored and do not count in the security audit. Before a restore, the dump and the archive are checked against the manifest hashes. Actions: `create_full`, `create_db`, `validate`, `download`, `restore`, `delete`, `set_encryption`.

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

### Speicherort (`CMS_BACKUP_PATH`, ab 4.0.0)

Ohne Angabe liegen Backups wie bisher in `CMS/backups/`. Dieses Verzeichnis liegt im Webroot und ist nur per `.htaccess` (Apache) bzw. eigener Serverregel (nginx) vor direktem Abruf geschützt. Empfohlen ist ein Verzeichnis **außerhalb des Webroots**, eingetragen in `CMS/config/app.php`:

```php
defined('CMS_BACKUP_PATH') || define('CMS_BACKUP_PATH', '/var/backups/365cms');   // absolut
defined('CMS_BACKUP_PATH') || define('CMS_BACKUP_PATH', '../365cms-backups');     // relativ zu CMS/
```

- Das Verzeichnis wird bei Bedarf mit den Rechten `0750` angelegt; der PHP-Benutzer braucht Schreibrechte.
- Abgelehnt werden die Dateisystem-Wurzel, das CMS-Verzeichnis und alle übergeordneten Verzeichnisse. Innerhalb des CMS-Verzeichnisses ist nur `backups/` oder ein Unterordner davon zulässig, weil der Datei-Restore `uploads/`, `themes/` und `plugins/` und ein Core-Update die übrigen Verzeichnisse ersetzt. Ein unzulässiger Pfad wird nicht angelegt; *System → Backup & Restore* zeigt dann die Ursache an.
- Die Karte *Speicherort & Verschlüsselung* zeigt den aktiven Pfad, ob er im Webroot liegt, und wie viele Backups noch im bisherigen Verzeichnis `CMS/backups/` liegen. Diese Altbestände erscheinen nach dem Umstellen nicht mehr in der Liste; bei Bedarf per SFTP/SSH in das neue Verzeichnis verschieben oder löschen.
- Die Update-Vorprüfung unter *System → Updates* prüft die Schreibrechte des konfigurierten Verzeichnisses. Die Rollback-Snapshots der Performance-Wartung bleiben unter `CMS/backups/performance-rollbacks/`.

### Verschlüsselung (ab 4.0.0)

Unter *System → Backup & Restore → Speicherort & Verschlüsselung* lässt sich die Verschlüsselung neuer Backups einschalten (Option `backup_encryption`, Aktion `set_encryption`, Audit-Ereignisse `backup.encryption.enabled`/`backup.encryption.disabled`).

- **Verfahren:** libsodium secretstream (XChaCha20-Poly1305) in Blöcken zu 64 KiB. Dump und Datei-Archiv werden nach `<datei>.enc` verschlüsselt, der Klartext wird danach gelöscht. Das Manifest erhält einen Block `encryption` (Format `cmsenc2`, Schlüssel-ID); die Prüfsummen beziehen sich auf die verschlüsselten Dateien.
- **Schlüssel:** `CMS_ENCRYPTION_KEY` aus `config/app.php` (ohne eigenen Schlüssel aus `AUTH_KEY`/`SECURE_AUTH_KEY` abgeleitet). Der Schlüssel liegt nie im Backup. **Ohne Schlüssel ist ein verschlüsseltes Backup nicht wiederherstellbar** – `config/app.php` bzw. den Schlüssel getrennt von den Backups sichern (Passwortmanager, Tresor).
- **Schlüsselwechsel:** Den bisherigen Schlüssel in `CMS_ENCRYPTION_KEYS_PREVIOUS` eintragen (mehrere durch Komma getrennt). Ältere Backups bleiben damit lesbar; `validate` nennt je verschlüsseltem Teil die verwendete Schlüssel-ID.
- **Ohne sodium:** Das Einschalten wird abgelehnt. Fehlt die Erweiterung bei aktiver Option, entsteht **kein** Backup statt eines unverschlüsselten.
- **Prüfen und Wiederherstellen:** Die Dateien werden zuerst gegen das Manifest geprüft und dann in ein temporäres Arbeitsverzeichnis (`.decrypt-*`, Rechte `0700`) entschlüsselt, das danach gelöscht wird. Manipulierte Dateien scheitern an der Prüfsumme bzw. an der Authentifizierung. Fehlt der Schlüssel, bricht die Wiederherstellung vor jeder Änderung ab und nennt die fehlende Schlüssel-ID. Der Sicherungs-Snapshot vor einer Wiederherstellung ist ein normales Voll-Backup und wird bei aktiver Option ebenfalls verschlüsselt.
- **Download:** Verschlüsselte Teile werden verschlüsselt heruntergeladen. Entschlüsseln außerhalb der Oberfläche – im CMS-Verzeichnis einer Installation mit derselben `config/app.php` (gleicher Schlüssel):

```bash
cd /pfad/zu/CMS
php -r 'require "config.php"; require "core/autoload.php"; \CMS\Services\CryptoService::getInstance()->decryptFile($argv[1], $argv[2]);' /pfad/database_….sql.gz.enc /tmp/database.sql.gz
```

- Das Ausschalten wirkt nur auf neue Backups; vorhandene bleiben verschlüsselt. Bei aktiver Option zeigt die Karte, wie viele ältere Backups noch unverschlüsselt sind.

### Status in der Übersicht

| Status | Bedeutung | Mögliche Aktionen |
|---|---|---|
| OK | Manifest lesbar, alle darin genannten Dateien vorhanden; verschlüsselte Backups tragen zusätzlich das Kennzeichen „Verschlüsselt“ | alle |
| Unvollständig | `manifest.json` fehlt oder ist beschädigt bzw. ein genannter Dump oder ein Archiv fehlt (z. B. Abbruch durch Zeitlimit oder vollen Speicher). Der Grund steht im Fehlerprotokoll des Eintrags | `validate`, Trockenlauf, `delete` |
| In Arbeit | Eigenes Backup-Verzeichnis ohne Manifest, jünger als eine Stunde (Erstellung läuft oder wurde gerade abgebrochen); danach „Unvollständig“ | `validate`, Trockenlauf, `delete` |

Unvollständige Backups zählen im Sicherheits-Audit nicht als Sicherung.

### Aktionen

| Aktion | Wirkung |
|---|---|
| `validate` | Prüft Vollständigkeit, Manifest, Hashes, Lesbarkeit und kritische Tabellen (`users`, `settings`, `pages`, `posts`); optional Trockenlauf der Wiederherstellung. Bei unvollständigen Backups meldet die Prüfung „Backup-Vollständigkeit – Blockiert“ |
| `download` | Einmaliger, sessiongebundener Download-Token je Datei und Teil (`database` oder `files`); nur für vorhandene Teile; verschlüsselte Teile bleiben verschlüsselt |
| `restore` | Spielt Datenbank-Dump und – falls vorhanden – Datei-Archiv zurück; nur bei Status OK |
| `delete` | Löscht das Backup |
| `set_encryption` | Schaltet die Verschlüsselung neuer Backups ein oder aus (siehe oben) |

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
- `CMS_BACKUP_PATH` auf ein Verzeichnis außerhalb des Webroots setzen. Solange Backups in `CMS/backups/` liegen, ist der Ordner per `.htaccess` gesperrt (Apache 2.2 und 2.4); eine ältere Datei mit nur `deny from all` ersetzt das CMS beim nächsten Backup. Unter nginx den Ordner in der Serverkonfiguration sperren.
- Verschlüsselung einschalten, wenn Backups im Webroot liegen oder extern abgelegt werden; den Schlüssel getrennt sichern.
- Backups regelmäßig außerhalb des Servers sichern.
- Das Sicherheits-Audit bewertet das jüngste vollständige Backup (bis 7 Tage OK, bis 30 Tage Warnung, älter oder keines kritisch) und seit 4.0.0 Speicherort und Verschlüsselung (*Backup-Speicherort & Verschlüsselung*). Externe Hoster-Backups kann es nicht erkennen.

### Verwandte Dokumente

[UPDATES.md](UPDATES.md) · [../security/SECURITY-AUDIT.md](../security/SECURITY-AUDIT.md) · [../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md](../../workflow/UPDATE-DEPLOYMENT-WORKFLOW.md)
