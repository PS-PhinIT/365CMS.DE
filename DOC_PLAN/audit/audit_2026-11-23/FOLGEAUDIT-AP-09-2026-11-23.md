# AP-09 – Installation, Update, Marketplace, Plugins, Themes (Version 4.2.9)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank. Maßstab: [Audit-Katalog, AP-09](../AUDIT-KATALOG-4.2.0.md#ap-09-installation-update-marketplace-plugins-themes). Der Bericht betrifft nur AP-09; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Sicherheitsbefund. Ein veralteter Kommentar zur Pflicht-Prüfsumme wurde angeglichen. Die gelesenen Pfade erfüllen die Prüfpunkte; Sicherung vor dem Datenbank-Update und das Verhalten nach einem Fehler sind nicht geprüft.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 09.1 Installer | ja (Stichprobe) | Nach der Installation gilt `config/install.lock` oder eine angepasste `config/app.php` als installiert; dann kommt nur durch, wer in der Konsole läuft oder Administrator ist (`CMS/install/InstallerController.php:48–75`, `CMS/install/InstallerService.php:canAccessInstalledInstaller()`). Der Parameter `reinstall` wirkt erst hinter dieser Sperre (`InstallerController.php:193`). Formular-Token je Schritt (`:34`). Diagnosetexte werden bereinigt (`sanitizeDiagnosticText()`). | Parallele Erstinstallation auf einem noch offenen System ist systembedingt. |
| 09.2 Update | ja | Pflicht-SHA-256: ohne gültigen Wert bricht die Installation ab (`CMS/core/Services/UpdateService.php:1259–1266`); nur HTTPS (`:946`), Host-Liste (`isAllowedSensitiveRemoteUrl()`) und öffentliche Ziele (`isSafeExternalUrl()`); Archivprüfer mit Anzahl- und Größengrenzen (`:2011–2043`, `UPDATE_MAX_ARCHIVE_*`); Entpacken in ein Staging-Verzeichnis (`:1376–1386`); Sperre `cache/.update.lock` mit Ablaufzeit, `Retry-After` (`CMS/index.php:14–30`). | Atomarer Tausch mit Rollback nur gelesen; die Suite `update-swap` läuft grün. |
| 09.3 Datenbank-Update | offen (Teil bestätigt) | Downgrade-Erkennung für Core und Schema, automatische Ausführung bricht ab (`CMS/core/DatabaseUpdateRunner.php:53–90`). | Sicherung vor dem Update, Fehler hinterlässt ein nutzbares System, Reihenfolge Code vor Schema. |
| 09.4 Plugins, Themes | ja (Stichprobe) | Archivprüfer (`CMS/core/PluginManager.php:743–790`). Filter in Sicherheitsentscheidungen: `cms_password_policy` nur verschärfend (`PasswordPolicyService::tighten()`, `:146–149`), `cms_mfa_enforcement_required` kann den Zwang nur zusätzlich einschalten (`MfaEnforcementService.php:164`), Captcha- und Formular-Token-Filter prüfen weiter gegen den Core. Hinweis INFO-1. | Aktivierung nur durch Admin (Plugin-Seiten) nicht neu geprüft. |
| 09.5 Marketplace-Feeds | ja (Stichprobe) | Ein Feed-Eintrag ohne SHA-256 ist „nur manuell“ (`install_supported = false`, `UpdateService.php:163–174, 286–305, 460–479`); die Download-Adresse unterliegt denselben HTTPS- und Host-Regeln wie das Kern-Update. Feed-Inhalte werden nicht ausgeführt. | – |
| 09.6 `RELEASE/` | ja | `sha256sum -c` für beide Pakete in Ordnung; `365CMS-4.2.0-update.json` trägt die SHA-256 des Update-Pakets und die Adresse unter `365cms.de/marketplace/core/`; keine Treffer für Geheimnis-Muster (private Schlüssel, AWS-, GitHub-, Stripe-, Slack-Token, DB-Passwort) in den Text- und Codedateien bis 2 MB (4 487 bzw. 4 475 Einträge); kein `config/app.php`, kein `.git`, `.github`, `TESTS`, `DOC_PLAN`, keine Inhalte aus `uploads/`, `backups/`, `cache/`, `private/`. | Hinweis INFO-2. |
| 09.7 OFFEN-27 | ja (Stand dokumentiert) | Signierte Kern-Update-Pakete bleiben offen und sind als 42X-06 geführt (`DOC_PLAN/audit/README.md`, Abschnitt „Offene Befunde“). Die Prüfsumme im Feed schützt vor beschädigten Paketen, nicht vor einem manipulierten Feed. | Umsetzung der Signatur (42X-06). |

## Abweichungen

Keine. Geändert wurde nur ein Kommentar: Der Docblock von `UpdateService::downloadAndInstallUpdate()` nannte die Prüfsumme „leer = Warnung, kein Abbruch“, der Code verlangt sie aber (Abbruch ohne gültigen Wert).

## Hinweise ohne Änderung

- **INFO-1:** `cms_download_access` darf den Zugriffszustand in beide Richtungen ändern (nur die vier bekannten Werte). Das ist dokumentiert (`DOC/core/HOOKS-REFERENCE.md`, Zeile zu `cms_download_access`) und dient Mitgliedschafts-Plugins; die Vertrauensgrenze ist das Plugin selbst, das als PHP-Code ohnehin volle Rechte hat.
- **INFO-2:** Das Vollpaket enthält `CMS/logs/.gitignore` und, im Bündel der KI-Plattform, einen Test der Fremdbibliothek (`CMS/assets/ai-platform/src/Result/Stream/Delta/Tests/ThinkingCompleteTest.php`). Beides ohne Wirkung; bei einer Bereinigung des Pakets zu entfernen (AP-16).

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux. Gesamtlauf aller Suiten grün; `sha256sum -c` und Archivprüfung per Skript.

**Offen:** 09.3 (Sicherung, Fehlerfall), Ausführung des atomaren Tauschs mit einem präparierten Paket, Aktivierungsrechte der Plugins.
