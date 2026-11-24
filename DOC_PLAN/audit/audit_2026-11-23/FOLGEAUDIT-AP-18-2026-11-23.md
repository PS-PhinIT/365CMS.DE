# AP-18 – Betrieb, Härtung, Wiederherstellung (Version 4.2.18)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank und ohne Browser. Maßstab: [Audit-Katalog, AP-18](../AUDIT-KATALOG-4.2.0.md#ap-18-betrieb-haertung-wiederherstellung). Der Bericht betrifft nur AP-18; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund. Eine Schutzprüfung für die dreifach vorhandene Mindest-PHP-Version ist neu. Sicherung und Wiederherstellung wurden nicht praktisch getestet.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 18.1 Webserver-Regeln | ja (Stichprobe) | `CMS/.htaccess:44–100`: Sperren für `config`, `core`, `logs`, `backups`, `cache`, `private`, `vendor`, `install/*.php`, `.git`, Sicherungsendungen (`.bak`, `.old`, `.orig` …), Paket-Metadaten, ausführbare Endungen und versteckte Dateien in `uploads/`, `uploads/member` und `uploads/import`. Eigene `.htaccess` in `config`, `logs`, `backups`, `private`, `uploads`. nginx-Beispiel in `DOC/INSTALLATION.md` nennt dieselben Verzeichnisse samt `install`, Sicherungsendungen und versteckten Dateien. | Keine Prüfung gegen laufenden Apache oder nginx. |
| 18.2 Informationsabfluss | ja (Teil) | 997 von 1 001 PHP-Dateien (ohne `vendor`, `assets`, Laufzeitordner) beginnen mit dem `ABSPATH`-Schutz; die Übrigen sind die Einstiegsdateien `index.php` und `cron.php` sowie zwei reine Klassendateien (`CommunityAccess.php`, `TopicService.php`); fünf Weiterleitungsdateien `admin/logs/*.php` laden Ziele, die selbst geschützt sind. Kein `phpinfo()` im Code; `display_errors` nur im Debug-Modus (`SettingsModule.php:881–887`), Statusprüfung warnt (`StatusService.php:314`). Siehe AP-09 für das Release-Paket. | Stacktraces in Fehlerseiten (AP-04). |
| 18.3 `cron.php`, `update.php` | ja | `cron.php`: Konsole oder Geheimnis, Kopf vor Parameter (`cron.php:78–91`); `update.php`: Konsole oder Administrator mit Recht `manage_settings` und CSRF-Token (`update.php:29–63`). | – |
| 18.4 Sicherung, Wiederherstellung | offen | Nicht praktisch getestet. Teile siehe AP-06 (Aufbewahrung, Prüfsummen) und AP-09 (Update-Rückfall). | Wiederherstellung mit einem Backup auf einer Testinstanz. |
| 18.5 Monitoring, Berichte | offen | Nicht geprüft. | Datenabfluss in Status- und Fehlerberichten. |
| 18.6 Plattformprüfung | ja | `CMS/config.php:29–50` bricht bei zu alter PHP-Version ab; derselbe Wert (8.4.1) steht im Installer (`InstallerService.php:607`) und in `Version::MIN_PHP`. Neue Schutzprüfung `TESTS/security/platform-minimum.php` hält die drei Stellen gleich (Mutante mit 8.3.0 in `config.php` wird erkannt). | Prüfung der PHP-Erweiterungen beim Start (nur im Installer geprüft). |

## Abweichungen

Keine.

## Hinweise ohne Änderung

- **INFO-1:** Die Mindestversion steht an drei Stellen. `config.php` kann nicht `Version::MIN_PHP` lesen, weil es vor dem Autoloader und der PHP-Version-Prüfung läuft; die Schutzprüfung ersetzt den fehlenden Zusammenhang.

## Testlauf und Übergabe

Skriptsuchen; Gesamtlauf aller Suiten grün.

**Offen:** 18.4, 18.5; Prüfung gegen einen laufenden Webserver (statische Dateien in `uploads/`, Sperren).
