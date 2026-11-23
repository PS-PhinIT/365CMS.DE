# Cron Library (poliander/cron 3.3.1)

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **English summary:** poliander/cron parses and evaluates cron expressions for the scheduler (`cron.php`, `CronExpressionAdapter`). Details in German below.

Diese Runtime-Library wird in `CMS/assets/cron/` gebuendelt und ueber `CMS/assets/autoload.php` per PSR-4 (`Poliander\Cron\`) geladen.

## Zweck in 365CMS

- Parser/Validator fuer Cron-Expressions (5 Segmente)
- Berechnung faelliger Zeitpunkte fuer den stuedlichen Core-Hook
- Berechnung des naechsten geplanten Laufs fuer Diagnose/Status

## Integration

- Adapter: `CMS/core/Services/CronExpressionAdapter.php`
- Scheduler-Nutzung: `CMS/core/Services/CronRunnerService.php`
- Admin-Status: `CMS/admin/modules/system/SystemInfoModule.php`
- Entry-Point: `CMS/cron.php` mit CLI/Web-Ausführung, Header-Token-Empfehlung (`X-CMS-Cron-Token`) und interner Fehlerprotokollierung

## Task-Vertrag

- `mail-queue` / `cms_cron_mail_queue`: verarbeitet die zentrale Mail-Queue und feuert den Hook `cms_cron_mail_queue`, damit Plugins wie `cms-feed` kleine Worker-Batches andocken können.
- `hourly` / `cms_cron_hourly`: prüft die konfigurierte Cron-Expression und feuert bei Fälligkeit `cms_cron_hourly` für Feed-Digests, Monitoring, SEO und Security-Snapshots.
- `all`: kombiniert Mail-Queue und stündlichen Scheduler; nach einem ausgeführten Hourly-Lauf wird die Mail-Queue erneut geleert, damit frisch erzeugte Benachrichtigungen direkt rausgehen.
- Bestehende Web-Crons mit `task=mail-queue` behalten aus Kompatibilitätsgründen die stündliche Bridge bei, sofern `cron.mail_queue_triggers_hourly` nicht explizit deaktiviert wird.

## Fallback-Verhalten

Wenn die externe Klasse nicht verfuegbar ist oder eine Expression ungueltig ist, faellt 365CMS auf die bisherige Intervall-Logik (>= 3600 Sekunden) zurueck.

## Quelle

- Upstream: [poliander/cron](https://github.com/poliander/cron)
- Website: https://packagist.org/packages/poliander/cron
- GitHub: https://github.com/poliander/cron
- Version: `3.3.1`
- Ursprungsablage im Repo: `ASSETS/cron-3.3.1`
