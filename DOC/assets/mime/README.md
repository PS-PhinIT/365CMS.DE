# Symfony Mime

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **English summary:** Symfony Mime builds the e-mail objects, headers and attachments for the local mail transport. Details in German below.

## Kurzbeschreibung

`Symfony Mime` erzeugt in 365CMS die eigentlichen E-Mail-Objekte, Header und Anhänge für den lokalen Mail-Transport.

## Quellordner

- `CMS/assets/mime/`

## Verwendung in 365CMS

- Eingebunden in: `CMS/core/Services/MailService.php`, `CMS/core/Services/MailQueueService.php`
- Funktion: Aufbau von `Symfony\Component\Mime\Email`-Objekten für SMTP-, Queue- und Attachment-Versand

## Abhängigkeiten

- Benötigt: `psr/` (indirekt über die Mailer-Kette)
- Wird benötigt von: `mailer/`

## Website / GitHub

- Website: https://symfony.com/components/Mime
- GitHub: https://github.com/symfony/mime

## Stand

- Zuletzt geprüft: 2026-03-08
- Version: lokales Bundle in `CMS/assets/` (8.x-Linie laut Asset-Bestand)
