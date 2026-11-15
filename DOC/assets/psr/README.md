# PSR-Interfaces

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable
> **English summary:** `CMS/assets/psr/` contains the official PHP-FIG interface packages so the bundled libraries work without Composer. Details in German below.

## Kurzbeschreibung

`CMS/assets/psr/` enthält die offiziellen PHP-FIG-Interfacepakete, damit die gebündelten Libraries ohne Composer-Installation funktionieren.

## Quellordner

- `CMS/assets/psr/Log/` – `psr/log` 3.0.2
- `CMS/assets/psr/EventDispatcher/` – `psr/event-dispatcher` 1.0.0
- `CMS/assets/psr/Container/` – `psr/container` 2.0.2
- `CMS/assets/psr/Clock/` – `psr/clock` 1.0.0

## Verwendung in 365CMS

- Eingebunden in: `CMS/assets/autoload.php`
- Wird benötigt von: `mailer/`, `event-dispatcher/`, `symfony-contracts/` (Service), `type-info/`, `clock/`, `Carbon/`, `ai-platform/`, Teile von `ldaprecord/`

## Website / GitHub

- Website: https://www.php-fig.org/psr/
- GitHub: https://github.com/php-fig

| Paket | Runtime-Pfad | Spezifikation | GitHub |
|---|---|---|---|
| `psr/log` | `CMS/assets/psr/Log/` | https://www.php-fig.org/psr/psr-3/ | https://github.com/php-fig/log |
| `psr/container` | `CMS/assets/psr/Container/` | https://www.php-fig.org/psr/psr-11/ | https://github.com/php-fig/container |
| `psr/event-dispatcher` | `CMS/assets/psr/EventDispatcher/` | https://www.php-fig.org/psr/psr-14/ | https://github.com/php-fig/event-dispatcher |
| `psr/simple-cache` | `CMS/assets/psr/SimpleCache/` | https://www.php-fig.org/psr/psr-16/ | https://github.com/php-fig/simple-cache |
| `psr/clock` | `CMS/assets/psr/Clock/` | https://www.php-fig.org/psr/psr-20/ | https://github.com/php-fig/clock |

## Stand

- Zuletzt geprüft: 2026-09-26
- Version: vollständige Originalpakete (vorher lokale Minimalimplementierung)