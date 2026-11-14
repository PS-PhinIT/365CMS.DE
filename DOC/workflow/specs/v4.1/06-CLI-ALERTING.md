# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Bereich 6: CLI & Echtzeit-Alerting
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1.0 · Schema v26 | **Status:** Spezifikation | **Übersicht:** [README.md](README.md)

## English (summary)

(6a) `CMS/cli.php`, a dependency-free console runner next to `cron.php` and `update.php` with commands for cache,
search index, backups, retention, users and health, extensible by plugins through a filter, refusing to run outside
the CLI SAPI. (6b) Real-time admin alerting: critical events (cron failures, failed or missing backups, failed
offsite uploads, critical core updates, bursts of firewall blocks, mail queue stuck) are raised through one
`AlertService`, delivered by e-mail via the mail queue and by signed webhooks (area 4b), with per-alert cooldown and
de-duplication.

## Deutsch

### 6a. Zentrale CLI (`CMS/cli.php`)

| Punkt | Festlegung |
|---|---|
| Aufruf | `php CMS/cli.php <befehl> [--option=wert] [--json] [--quiet]`; nur `PHP_SAPI === 'cli'`, sonst 404 (wie `cron.php`); `.htaccess` sperrt den Webzugriff zusätzlich |
| Bootstrap | gleicher Lade-Weg wie `cron.php` (Konfiguration, Autoloader, Datenbank), keine Sitzung, kein Theme |
| Ausgabe | Text oder JSON (`--json`), Exit-Code 0/1/2 (Erfolg/Fehler/Benutzungsfehler) |
| Befehle | `cache:clear [--assets\|--opcache\|--all]`, `search:reindex [--type=]`, `backup:create [--type=full\|database] [--offsite]`, `backup:list`, `retention:run [--rule=]`, `user:create --username --email --role [--password-stdin]`, `user:reset-password --user= [--password-stdin\|--send-link]`, `system:health`, `cron:run --task=`, `schema:status`, `alerts:test` |
| Passwörter | nie als Argument (Prozessliste/Shell-History): `--password-stdin` liest von STDIN; Richtlinie aus Bereich 1a gilt |
| Audit | jeder schreibende Befehl erzeugt einen Audit-Log-Eintrag mit Quelle `cli` und Betriebssystem-Benutzer |
| Erweiterung | Filter `cms_cli_commands` (Name → Callable, Beschreibung); Plugins können keine Core-Befehle überschreiben |
| Umsetzung | eigener kleiner Parser (`CMS\Cli\Application`, `Command`-Schnittstelle); keine Bibliothek nötig (siehe [REFERENCES.md](REFERENCES.md)) |

`system:health` prüft: PHP-Version und Erweiterungen (`SystemService`), Schema-Stand, Schreibrechte, Cron zuletzt
gelaufen, Mail-Warteschlange (älteste ausstehende), Backup-Alter, Offsite-Status, Speicherplatz, Wartungsmodus;
Ausgabe als Ampel und JSON (für Monitoring).

Tests (`TESTS/cli/run.php`): Parser (Optionen, Fehler), Ablehnung außerhalb CLI, jeder Befehl mit Fake-Diensten,
`--password-stdin`, Exit-Codes, JSON-Schema von `system:health`.

### 6b. Echtzeit-Alerting

| Punkt | Festlegung |
|---|---|
| Dienst | `CMS\Services\Alerts\AlertService::raise(string $key, string $severity, string $title, string $message, array $context = [])` |
| Quellen | `CronRunnerService` (Task-Fehler, Laufzeit überschritten), `BackupService` (fehlgeschlagen, letztes Backup älter als Schwelle), `OffsiteBackupService` (Upload fehlgeschlagen), `UpdateService` (kritisches Core-Update verfügbar), Firewall/`security_log` (> X Sperren in Y min), Mail-Warteschlange (älteste ausstehende > Z min), Webhook-Empfänger automatisch deaktiviert |
| Kanäle | E-Mail an Empfängerliste (Mail-Warteschlange, Priorität hoch; bei defekter Warteschlange direkter Versand über `MailService`), Webhook-Ereignis `alert.raised` (4b), Admin-Hinweis (`AdminNoticeService`, bleibt bis bestätigt) |
| Cooldown | je `key` (z. B. `cron.failed:daily`) Standard 60 min, je Schweregrad einstellbar; innerhalb des Cooldowns nur Zähler erhöhen; nach Ablauf eine Zusammenfassung „trat 7× auf“ |
| Entwarnung | optionale „behoben“-Meldung, wenn die Quelle wieder gesund ist |
| Einstellungen | *System → Alerting*: Kanäle, Empfänger, Schweregrad-Schwelle je Kanal, Cooldowns, Testalarm; Capability `alerts.manage` |
| Hook | `cms_alert_raised` |

```sql
CREATE TABLE {p}alert_state (
  alert_key VARCHAR(120) PRIMARY KEY,
  severity VARCHAR(10) NOT NULL,
  last_sent_at DATETIME NULL, last_raised_at DATETIME NOT NULL,
  suppressed_count INT UNSIGNED NOT NULL DEFAULT 0,
  resolved_at DATETIME NULL, last_context_json LONGTEXT NULL
);
```

Tests (`TESTS/alerting/run.php`): Cooldown und Zusammenfassung, Schwellen je Kanal, Ausfall der Mail-Warteschlange →
Direktversand, Entwarnung, keine Geheimnisse im Kontext, Webhook-Ereignis erzeugt.

**Referenzen:** CLI-Bibliotheken (`php-school/cli-menu`, `splitbrain/php-cli`, `adhocore/php-cli`) und
Notification-Pakete werden nicht eingeführt – der Umfang ist mit eigenem Code kleiner als eine neue Abhängigkeit
([REFERENCES.md](REFERENCES.md)).
