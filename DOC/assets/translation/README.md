# Symfony Translation

> **Stand:** 2026-10-04 | **Version:** 8.1.5 | **Status:** Aktiv

## Kurzbeschreibung

`Symfony Translation` übernimmt die Internationalisierung von 365CMS.

## Quellordner

- `CMS/assets/translation/`
- `CMS/assets/yaml/` (`symfony/yaml` 8.1.11 zum Parsen von `CMS/lang/*.yaml`)

## Verwendung in 365CMS

- zentrale Nutzung in `CMS/core/Services/TranslationService.php`: Kataloge werden per Symfony Yaml geparst und je Domain über den `ArrayLoader` registriert (der `YamlFileLoader` würde `default:` zu `default.Key` abflachen)
- unbekannte Keys laufen über `MessageCatalogue::has()` direkt in den Fallback-Katalog
- zusätzlich Basis der Carbon-Lokalisierung

## Abhängigkeiten

- `symfony-contracts` (Translation-Contracts 3.6.1, Symfony 8.1 verlangt `^3.6.1`), `symfony/polyfill-mbstring` (nur ohne ext-mbstring)
- PHP `>= 8.4.1` (Symfony 8.1)
- Nicht übernommen: `Command/`, `DataCollector/`, `DependencyInjection/`, `Extractor/`, `DataCollectorTranslator` (Framework-/Tooling-Integration)

## Website / GitHub

- Website: https://symfony.com/components/Translation
- GitHub: https://github.com/symfony/translation
- YAML-Parser für die Sprachkataloge: https://symfony.com/components/Yaml / https://github.com/symfony/yaml