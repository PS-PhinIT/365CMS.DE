# Workflow: Update und Deployment

> **Stand:** 2026-11-23 | **Zielstand:** 4.2.0 | **Schema:** v27

1. Backup von Datenbank und installationsspezifischen Dateien erstellen.
2. Vor dem Einspielen die SHA-256-Prüfsumme gegen `RELEASE/365CMS-4.2.0-SHA256SUMS.txt` prüfen.
3. Update-Paket `365CMS-4.2.0-update.zip` verwenden; `config.php`, `config/app.php`, Uploads, Logs, Backups und private Laufzeitdaten nicht überschreiben.
4. Unter **Admin → Updates** das Datenbank-Update starten. `DatabaseUpdateRunner` führt den Weg auf Schema `v27` aus; Fehler nicht durch manuelles Setzen der Schemakennung umgehen.
5. Die Community-Capabilities und die Systemrolle `moderator` kontrollieren. `capabilities_v27` vergibt Community-Rechte nur an die dafür vorgesehenen Standardrollen.
6. Nach dem Update `php TESTS/run-all.php` ausführen. Der Runner entdeckt alle vorhandenen `TESTS/<suite>/run.php`; externe Datenbank- und Browserpfade können je Umgebung übersprungen werden.
7. Admin, Mitgliederbereich, Community, Suche, Medien und Update-Anzeige mit einer frischen Sitzung prüfen.

Release-Hinweise: [../../RELEASE/README-4.2.0.md](../../RELEASE/README-4.2.0.md).

