# Symfony Mailer

> **English summary:** Symfony Mailer is the transport layer of the mail system (SMTP, queue integration). Details in German below.
> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Bibliothek:** Symfony Mailer 8.1.7 | **Status:** Aktiv

`Symfony Mailer` bildet in 365CMS die lokale Versandschicht für SMTP, Queue-Worker und OAuth-/XOAUTH2-nahe Mail-Flows.

## Quellordner

- `CMS/assets/mailer/`

## Verwendung in 365CMS

- Eingebunden in: `CMS/assets/autoload.php`, `CMS/core/Services/MailService.php`, `CMS/core/Services/MailQueueService.php`, `CMS/cron.php`
- Funktion: Versand von E-Mails über SMTP-Transporte, Retry-/Queue-Verarbeitung und Integrationsbasis für Mail-Provider-Konfigurationen
- Produktivstatus: aktiv; es gibt aktuell **keinen** separaten Legacy-Mailer-Ordner unter `CMS/assets/`

## Abhängigkeiten

- Benötigt: `mime/`, `egulias-email-validator/` + `doctrine-lexer/` (Pflicht für `Mime\Address`; ohne sie bricht jeder SMTP-Versand ab), `event-dispatcher/`, `symfony-contracts/`, `psr/`, `polyfill-intl-idn/` (IDN-Adressen ohne ext-intl)
- Wird benötigt von: `MailService`, `MailQueueService`, Cron-Mailversand

## Pflegehinweise

- Wegen RFC-/Provider-Kompatibilität nicht als Eigenbau nachbauen, sondern hinter den bestehenden Mail-Services kapseln
- Falls langfristig abstrahiert werden soll, zuerst Transport-Konfiguration, Queue und Fehlerobjekte im Service-Layer stabilisieren
- Architektur und Konfiguration: [../../admin/system-settings/MAIL.md](../../admin/system-settings/MAIL.md)

## Website / GitHub

- Website: https://symfony.com/components/Mailer
- GitHub: https://github.com/symfony/mailer
- Pflicht-Abhängigkeit für Adressvalidierung: https://github.com/egulias/EmailValidator (`egulias/email-validator`) mit https://github.com/doctrine/lexer (`doctrine/lexer`)
