# Carbon

> **English summary:** Carbon is the central date and time library of 365CMS. Details in German below.

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Aktiv

## Kurzbeschreibung

`Carbon` ist die zentrale Datums- und Zeitbibliothek in 365CMS.

## Quellordner

- `CMS/assets/Carbon/src/Carbon/` (PSR-4 `Carbon\`, inkl. `Lang/`)
- `CMS/assets/Carbon/lazy/Carbon/` (wird von `Translator`, `CarbonPeriod` und `MessageFormatterMapper` relativ per `__DIR__/../../lazy` geladen – Struktur nicht verändern)

## Verwendung in 365CMS

- relative Zeitangaben in `time_ago()` (`CMS/includes/functions/redirects-auth.php`)
- Lokalisierung über den Carbon-Translator (Symfony Translation)

## Abhängigkeiten

- `symfony/clock` (`CMS/assets/clock/`), `psr/clock` (`CMS/assets/psr/Clock/`)
- `symfony/translation` (`CMS/assets/translation/`), `symfony-contracts`
- `symfony/polyfill-mbstring` nur ohne ext-mbstring
- Nicht übernommen: `Laravel/`, `PHPStan/`, `Cli/` (Framework-/Tooling-Integration) sowie das im Archiv `CMS_ASSETS/Carbon-3.14.2.zip` enthaltene `vendor/`

## Website / GitHub

- Website: https://carbonphp.github.io/carbon/
- GitHub: https://github.com/CarbonPHP/carbon
- Packagist: https://packagist.org/packages/nesbot/carbon
- Hinweis (2026-10-04): Das Repository ist von `briannesbitt/Carbon` nach `CarbonPHP/carbon` umgezogen; der Paketname `nesbot/carbon` bleibt gleich.