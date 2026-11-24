# AP-07 – Kryptografie, Geheimnisse, Konfiguration (Version 4.2.7)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank. Maßstab: [Audit-Katalog, AP-07](../AUDIT-KATALOG-4.2.0.md#ap-07-kryptografie-geheimnisse-konfiguration). Der Bericht betrifft nur AP-07; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Ein Befund (MEDIUM bis LOW, umgesetzt): Der Installer legte `config/app.php` mit Datenbankpasswort und Schlüsseln für alle lesbar an. Dazu zwei Hinweise für den Betreiber.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 07.1 Zufall | ja | Skriptsuche über Kern, Admin, Mitglieder, Installer, Themes: kein `rand()`, `mt_rand()`, `str_shuffle()`, `array_rand()` für Tokens oder Schlüssel; `uniqid()` nur für Namen temporärer Dateien, Anzeige-IDs und Anbieter-Kennungen (z. B. `MediaRepository.php:954`, `EditorJsRenderer.php:1167`). Token mit `random_bytes()`. | – |
| 07.2 Verschlüsselung | ja | Funktionsaufruf `CryptoService`: zwei Verschlüsselungen desselben Werts sind verschieden, Rundlauf stimmt; anderer Kontext, andere Bindung, veränderter Text, abgeschnittener Wert und Müll werfen Ausnahmen; falscher Schlüssel und Manipulation sind nicht zu unterscheiden („falscher Schlüssel oder veränderte Daten“) (`CMS/core/Services/CryptoService.php:157–260`). | Rotation mit `CMS_ENCRYPTION_KEYS_PREVIOUS` nur gelesen (`:540`). |
| 07.3 Vergleich, Hash | ja (Stichprobe) | Signaturen mit `hash_equals` (Stripe `StripeGateway.php:84`, Rückgabe-Signatur `PaymentService.php:222`, Geräte-Cookie `Auth.php`); Passwort-Reset-Token nur als SHA-256 gespeichert (`CmsAuthPageService.php:674–683, 787`). Skriptsuche nach `===` mit Geheimnis-Variablen: keine Treffer. | Newsletter: `optin_token` liegt im Klartext, weil derselbe Link in jeder Ausgabe stehen muss (INFO-1). |
| 07.4 Geheimnisse | offen (Teil bestätigt) | S3-Geheimnis wird verschlüsselt gespeichert und nie ausgegeben (`OffsiteBackupService.php:112–141`, Feld `type=password` mit Platzhalter statt Wert in `admin/views/system/backup-settings.php:109`); Entra-Geheimnis nur als „gesetzt“ geführt (`EntraSettings.php:52`). | Protokolle, Exporte und Backups auf Klartext-Geheimnisse; SMTP, KI und Zahlungsschlüssel. |
| 07.5 Sichere Defaults | ja, mit Befund | `CMS_DEBUG` standardmäßig aus (`config/app.php:26`), `config/.htaccess` sperrt, keine Beispielkonten (Skriptsuche), Statusprüfung „Standard-Admin“ (`StatusService.php:326`). Befund TMP-AP07-1. | Hinweis INFO-2. |
| 07.6 Zeit | offen | Nicht geprüft. | Mit AP-10 (UTC, Toleranz). |

## Abweichungen

### TMP-AP07-1 · Konfiguration vom Installer mit Standardrechten angelegt (MEDIUM bis LOW, umgesetzt)

- **Fundstelle:** `CMS/install/InstallerService.php` (`createConfigFile()`): `file_put_contents()` und `copy()` ohne `chmod`.
- **Ablauf:** Bei der üblichen Umask 022 entstehen `config/app.php` und `config/app.backup-*.php` mit 0644. Beide enthalten Datenbankpasswort, `AUTH_KEY`, `SECURE_AUTH_KEY`, `JWT_SECRET` und `NONCE_KEY`. Nachgestellt mit einem Aufruf in einem leeren Verzeichnis: beide Dateien 0644. Das Einstellungsmodul setzt beim späteren Schreiben 0640 und korrigiert ältere Sicherungskopien mit dem Hinweis auf „Standardrechte, meist 0644, für alle lesbar“ (`admin/modules/settings/SettingsModule.php`, `backupConfigFile()`); nur der Installer fehlte.
- **Auswirkung:** Auf Servern, auf denen andere Konten das Verzeichnis lesen können (geteilte Hosts), liegen Zugangsdaten für alle lesbar. Der Webzugriff ist durch `config/.htaccess` gesperrt.
- **Korrektur:** Datei vor dem Schreiben der Geheimnisse anlegen und auf 0640 setzen, Sicherungskopie ebenfalls (`CONFIG_FILE_MODE`).
- **Regression:** `TESTS/security/config-permissions.php` ruft `createConfigFile()` zweimal mit Umask 022 auf und prüft 0640 für Konfiguration und Kopie; ohne die Korrektur schlagen drei Prüfungen fehl. **Status:** umgesetzt. **Konfidenz:** hoch.

## Hinweise ohne Änderung

- **INFO-1:** Der Newsletter-Token `optin_token` steht im Klartext in der Datenbank (Bestätigen, Abmelden, Webansicht). Wer die Datenbank lesen kann, könnte Abonnenten abmelden; Folgen sind gering. Eine Ableitung per HMAC statt Speicherung wäre die Alternative.
- **INFO-2 (aus AP-05):** Fehlen `JWT_SECRET`, `SECURE_AUTH_KEY` und `AUTH_KEY` (oder tragen Platzhalter), fallen Gerätecookie (`Auth.php:1503`), TOTP-Sperre (`Auth/AuthManager.php:508`), Zwischenspeicher (`CacheManager.php:91`), Downloads (`DownloadSecurity.php:47`) und Formular-Spamschutz (`FormSpamGuard.php:277`) auf ableitbare Werte zurück. Der Installer erzeugt alle Schlüssel; relevant ist nur eine unvollständig eingerichtete Installation. Empfehlung: Start mit einer deutlichen Meldung im Admin (`CryptoService::hasWeakKey()` gibt es bereits) statt stillem Rückfall.

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux. `security`: grün (neue Prüfung rot ohne, grün mit Korrektur).

**Offen:** 07.4 (Protokolle, Exporte, Backups), 07.6 (mit AP-10), Verhalten der Rotation mit einer echten Schlüsseländerung.
