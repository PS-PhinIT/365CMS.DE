# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Bewertung der Referenzen
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1.0 | **Status:** Bewertung abgeschlossen | **Übersicht:** [README.md](README.md)

## English (summary)

Assessment of every repository and the security advisory linked in the 4.1.0 brief. Each entry was cloned shallowly
on 14 Nov 2026 and only read (never executed): licence (LICENSE file and composer.json), last commit, kind of project
and suitability. Result: no new runtime dependency is needed for areas 1, 4 and 6; the existing core libraries
(`robthree/twofactorauth`, `firebase/php-jwt`, `lbuchs/WebAuthn`, Dompdf) plus small native clients cover the scope.
The phpMyFAQ advisory GHSA-6hhv-gc4g-f4r7 (Entra ID account takeover through `preferred_username`) directly shapes
the account-linking rules of the SSO design.

## Deutsch

**Legende:** ✅ wird genutzt · ➖ nur als Lektüre/Vergleich · ❌ ungeeignet (Grund)

### Bereich 1 – Identität

| Referenz | Lizenz / letzter Commit | Art | Bewertung |
|---|---|---|---|
| [GHSA-6hhv-gc4g-f4r7](https://github.com/thorsten/phpMyFAQ/security/advisories/GHSA-6hhv-gc4g-f4r7) (phpMyFAQ) | – / veröffentlicht 03.10.2026 | Sicherheitsmeldung: Entra-ID-Login übernimmt lokale Konten, weil `preferred_username` (veränderbar) als Schlüssel diente, Kollisionen still übernommen und Konten reaktiviert/E-Mail überschrieben wurden (CWE-287/290/863, CVSS 7.4) | ✅ **maßgeblich**: Bindung nur über `tid`+`oid`, keine Übernahme bei Kollision, keine Reaktivierung, keine E-Mail-Überschreibung, `state`/`nonce` Pflicht ([01-IDENTITY.md](01-IDENTITY.md) §3.3) |
| [robthree/twofactorauth](https://github.com/robthree/twofactorauth) | MIT / 05.01.2026, PHP ≥ 8.2 | TOTP-Bibliothek | ✅ bereits im Core (`assets/twofactorauth`, `TotpAdapter`); Version beim Update auf aktuellen Stand prüfen |
| [nahisaho/entra-id-sso-samples](https://github.com/nahisaho/entra-id-sso-samples) | MIT / 30.06.2025 | Beispielsammlung zu einem Fachbuch (Slim + onelogin/php-saml, JS-PKCE-Beispiel) | ➖ Lektüre; SAML-Beispiel lässt `x509cert` im Code leer bzw. aus der Umgebung – keine Vorlage für Produktionscode; 365CMS nutzt OIDC statt SAML |
| [windhamg/simplesamlphp-module-msverifiedid](https://github.com/windhamg/simplesamlphp-module-msverifiedid) | LGPL-2.1 / 10.01.2023 | SimpleSAMLphp-Modul für Microsoft Verified ID | ❌ anderer Anwendungsfall (verifizierbare Nachweise), setzt SimpleSAMLphp voraus, seit 2023 ohne Pflege |
| [AndrewRose/oauth.php](https://github.com/AndrewRose/oauth.php) | MIT / 18.10.2026 | Minimales Skript „Login mit Google/Microsoft“ zur E-Mail-Bestätigung | ❌ verknüpft über die E-Mail-Adresse – genau das Muster, das GHSA-6hhv-gc4g-f4r7 zeigt; keine `nonce`/JWKS-Prüfung als Bibliothek nutzbar |
| [nishadil/MFA](https://github.com/nishadil/MFA) | MIT / 09.01.2026, PHP ≥ 7.4 | TOTP/HOTP-Bibliothek | ❌ doppelt zu `robthree/twofactorauth` |
| [NORSUCMS/norsucms-2fa](https://github.com/NORSUCMS/norsucms-2fa) | MIT / 22.01.2020 | 2FA per E-Mail-Code, Beispielprojekt | ❌ E-Mail-Codes sind kein starker zweiter Faktor für Admins; seit 2020 ohne Pflege |

### Bereich 2 – Medien

| Referenz | Lizenz / letzter Commit | Art | Bewertung |
|---|---|---|---|
| [intervention/image](https://github.com/intervention/image) | MIT / 14.11.2026, PHP ^8.3 | Bildbibliothek (GD/Imagick) | ❌ für 4.1.0 nicht nötig: `ImageService` nutzt GD direkt und hat `convertToAvif()`; eine neue große Abhängigkeit bringt keinen Mehrwert für AVIF |
| [beamtic/php-photo-gallery](https://github.com/beamtic/php-photo-gallery) | Apache-2.0 / 04.04.2026 | Galerie-Anwendung | ➖ Vergleich (keine Bibliothek) |
| [maxhaesslein/mh.gallery](https://github.com/maxhaesslein/mh.gallery) | GPL / 14.10.2026 | Galerie-Anwendung | ❌ GPL, Anwendung statt Bibliothek |
| [smottt/WideImage](https://github.com/smottt/WideImage) | GPL-2.0/LGPL-2.1 / 10.01.2021, PHP ≥ 5.6 | Bildbibliothek | ❌ seit 2021 ohne Pflege, kein AVIF |
| [maximal/php-avif-thumbnailer](https://github.com/maximal/php-avif-thumbnailer) | MIT / 24.11.2022 | AVIF über externes Programm | ❌ ruft ein Kommandozeilen-Werkzeug per `exec()` auf (`src/AvifThumbnailer.php`) – Shell-Aufrufe sind im Core ausgeschlossen |
| [rosell-dk/avif-convert](https://github.com/rosell-dk/avif-convert) | ohne Lizenz / 22.09.2020 | nur README („The plan is to make a library…“), **kein Code** | ❌ |

### Bereich 4 – Backups

| Referenz | Lizenz / letzter Commit | Art | Bewertung |
|---|---|---|---|
| [sebastianfeldmann/phpbu](https://github.com/sebastianfeldmann/phpbu) | MIT / 03.07.2026, PHP ≥ 8.1 | Backup-Framework (CLI) mit vielen Zielen | ➖ Ideen für Retention-Regeln; als Abhängigkeit zu groß |
| [sebastiansulinski/php-backup](https://github.com/sebastiansulinski/php-backup) | MIT / 16.02.2026, PHP ^8.2 | Backup-Bibliothek (Flysystem) | ❌ zieht Flysystem/AWS-SDK nach – Auftrag verlangt einen schlanken nativen Client |
| [ianneub/php_backup_s3](https://github.com/ianneub/php_backup_s3) | ohne Lizenz / 06.04.2015 | Skript | ❌ ohne Lizenz, veraltet (vor SigV4-Pflicht) |
| [wisecom-oy/atlas](https://github.com/wisecom-oy/atlas) | Apache-2.0 / 03.11.2026 | Microsoft-365-Backup-Engine | ➖ anderes Thema (M365-Daten), Lektüre |
| [achimismaili/SharePointBackup](https://github.com/achimismaili/SharePointBackup) | ohne Lizenz / 02.12.2021 | PowerShell-Skript | ❌ kein PHP, ohne Lizenz |
| [OghabTeam/xui-backup](https://github.com/OghabTeam/xui-backup) | ohne Lizenz / 04.08.2024 | FTP-Upload-Skript | ❌ FTP, ohne Lizenz |

Grundlage für den S3-Client sind die AWS-Dokumentation zu Signature Version 4 und die veröffentlichten SigV4-Testvektoren.

### Bereich 5 – Zahlungen & PDF

| Referenz | Lizenz / letzter Commit | Art | Bewertung |
|---|---|---|---|
| [stripe/stripe-php](https://github.com/stripe/stripe-php) | MIT / 12.11.2026, PHP ≥ 7.4 | offizielles SDK | ➖ Referenz für Endpunkte und **Webhook-Signaturschema**; eigener schlanker Client (nur Checkout Session, Ereignisse, Abo) statt SDK |
| [cartalyst/stripe](https://github.com/cartalyst/stripe) | BSD-3 / 07.07.2022 | inoffizieller Client | ❌ seit 2022 ohne Pflege |
| [bradtraversy/php_stripe_paypage](https://github.com/bradtraversy/php_stripe_paypage), [allphptricks/…stripe…](https://github.com/allphptricks/integrate-stripe-payment-gateway-using-php) | ohne Lizenz / 2018, 2023 | Tutorials | ❌ ohne Lizenz, veraltete Muster (Charges-API statt Checkout/PaymentIntents) |
| [paypal/PayPal-PHP-Server-SDK](https://github.com/paypal/PayPal-PHP-Server-SDK) | MIT (composer, keine LICENSE-Datei) / 30.10.2026 | offizielles SDK | ➖ Referenz für Orders v2; PayPal direkt nur optional (V4) |
| [shopware/paypal-sdk](https://github.com/shopware/paypal-sdk) | MIT / 12.11.2026, PHP ≥ 8.2 | Shopware-spezifisches SDK | ❌ an Shopware-Strukturen gebunden |
| [phppdf/phppdf](https://github.com/phppdf/phppdf), [dragonofmercy/php-pdf](https://github.com/dragonofmercy/php-pdf) | MIT / 2026, PHP ≥ 8.4 | PDF-Bibliotheken | ❌ nicht nötig – `PdfService` (Dompdf) ist vorhanden |
| [rospdf/pdf-php](https://github.com/rospdf/pdf-php) | MIT / 20.04.2023 | PDF-Bibliothek | ❌ nicht nötig, ältere API |

### Bereich 6 – CLI & Benachrichtigungen

| Referenz | Lizenz / letzter Commit | Art | Bewertung |
|---|---|---|---|
| [php-school/cli-menu](https://github.com/php-school/cli-menu) | MIT / 22.10.2026 | interaktive Menüs | ❌ CLI soll skriptfähig sein, nicht interaktiv |
| [splitbrain/php-cli](https://github.com/splitbrain/php-cli), [adhocore/php-cli](https://github.com/adhocore/php-cli) | MIT / 2026, 2025 | Argument-Parser/Konsolen-Hilfen | ➖ gute Vorbilder; eigener Parser (~200 Zeilen) vermeidet eine Abhängigkeit |
| [kiwilan/php-notifier](https://github.com/kiwilan/php-notifier) | MIT / 12.01.2026 | Benachrichtigungen (Mail, Slack, Discord) | ❌ Mail läuft über die Core-Warteschlange, Webhooks über 4b |
| [KajalBhammar/Notification-System-using-PHP](https://github.com/KajalBhammar/Notification-System-using-PHP) | ohne Lizenz / 26.03.2024 | Beispielprojekt | ❌ ohne Lizenz |

### Ergebnis

Für 4.1.0 wird **keine neue Laufzeit-Abhängigkeit** eingeführt. Vorhandene Core-Bibliotheken reichen:
`robthree/twofactorauth` (TOTP), `firebase/php-jwt` (JWKS/ID-Token), `lbuchs/WebAuthn` (Passkeys, UV-Flag), Dompdf
(Rechnungen). Neu sind schlanke eigene Clients: OIDC (Entra), S3 (SigV4), Stripe/Mollie (REST + Webhook-Prüfung),
Webhooks, CLI-Parser.
