# Community: Testplan

## Ausführung

```text
php TESTS/run-all.php
```

Der Runner entdeckt `TESTS/*/run.php` dynamisch; die Dokumentation führt deshalb keine feste Summe. Mit `-v` werden Details ausgegeben.

## Community-Suiten

- `schema-v27`, `schema-upgrade`
- `community-settings`, `community-repositories`, `community-access`, `community-content`
- `community-render`, `community-xss`, `community-spam`
- `community-moderation`, `community-sanctions`, `community-privacy`, `community-trash`
- `community-captcha`, `community-editor`
- `community-frontend`, `community-member`, `community-api`, `community-admin`
- `community-notify`, `community-uploads`, `community-search-seo`
- `community-performance`, `community-a11y`, `community-lighthouse`
- `community-db`, `community-mutants`

## Verifikationsregeln

- Reine PHP-/Source-Suiten laufen ohne zusätzliche Dienste.
- MariaDB/MySQL-Suiten benötigen die dokumentierten `CMS_TEST_DB_*`-Variablen.
- Browserteile laufen nur, wenn Node, Playwright/axe/Lighthouse und ein Chromium verfügbar sind.
- Eine übersprungene optionale Umgebung ersetzt keine Aussage über einen fehlgeschlagenen Pflichtpfad.

