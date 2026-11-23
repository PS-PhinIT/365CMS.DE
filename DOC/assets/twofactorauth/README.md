# RobThree TwoFactorAuth

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **English summary:** RobThree/TwoFactorAuth provides TOTP-based multi-factor authentication. Details in German below.

## Kurzbeschreibung

`RobThree/TwoFactorAuth` liefert TOTP-basierte Mehrfaktor-Authentifizierung.

## Quellordner

- `CMS/assets/twofactorauth/`

## Verwendung in 365CMS

- direkte Nutzung in `CMS/core/Auth/MFA/TotpAdapter.php`
- Einbindung über `CMS/assets/autoload.php`

## QR-Codes

- lokal erzeugt über `BaconQrCodeProvider` (Format `svg`) aus `CMS/assets/bacon-qr-code/` (bacon/bacon-qr-code 3.1.1, BSD-2-Clause) und `CMS/assets/dasprid-enum/` (dasprid/enum 1.0.7)
- kein externer QR-Dienst (Secret verlässt den Server nicht), keine GD-/Imagick-Abhängigkeit (nur ext-iconv)
- `TotpAdapter::getQrCodeDataUri()` liefert `data:image/svg+xml;base64,…` (CSP `img-src data:`); `startSetup()` gibt die `otpauth://`-URI nie mehr als Bildquelle aus
- angezeigt auf `/mfa-setup` (`PublicRouter::renderMfaSetup`) und im Member-Bereich unter Sicherheit

## Website / GitHub

- Website: https://robthree.github.io/TwoFactorAuth/
- GitHub: https://github.com/RobThree/TwoFactorAuth
- QR-Code-Abhängigkeiten: https://github.com/Bacon/BaconQrCode (`bacon/bacon-qr-code`) und https://github.com/DASPRiD/Enum (`dasprid/enum`)