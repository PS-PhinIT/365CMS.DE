# AP-15 – Best Practice und Codequalität (Version 4.2.15)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe; Maßstab: [Audit-Katalog, AP-15](../AUDIT-KATALOG-4.2.0.md#ap-15-best-practice-und-codequalitaet). Der Bericht betrifft nur AP-15; er ist keine Freigabe des gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund in den gelesenen Teilen. Drei leere, versteckte Dateien wurden entfernt. Die meisten Punkte brauchen eine größere Durchsicht.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 15.1 PHP 8.4/8.5 | ja (Teil) | 992 PHP-Dateien außerhalb von `vendor`, `assets`, Laufzeitordnern (989 nach dem Entfernen der drei leeren Dateien, siehe unten): 0 implizit nullable Parameter (Skript über die Token-Liste); `declare(strict_types=1)` steht bei allen bis auf die Vorlage `themes/cms-default/index.php` und die Plugin-Seiten `plugins/cms-importer/admin/log.php` und `page.php` in den ersten 3 000 Zeichen; drei Community-Dateien (`PostService`, `TopicService`, `CommunityAccess`) haben die Zeile erst hinter einem langen Kopfkommentar (Zeile 36 bis 88). | Dynamische Eigenschaften und weitere Veraltungen unter PHP 8.4/8.5 (lokal läuft PHP 8.3). |
| 15.2 Fehlerbehandlung | offen (Teil) | 1 792 `catch`-Blöcke, davon 210 ohne jede Anweisung und ohne Kommentar, 79 nur mit Kommentar. | Stichprobe der leeren Blöcke auf verschluckte Fehler; ungeprüfte `false`-Rückgaben. |
| 15.3 Logging | offen | Nicht geprüft (IP im 404-Monitor: AP-17). | Passwörter, Token, Log-Injection. |
| 15.4 Struktur | offen | Nicht geprüft. | Mehrfach vorhandene Sicherheitslogik (siehe AP-20, INFO-2: fünf CSV-Schutzfunktionen). |
| 15.5 CDN, Laufzeit-Netz | ja (Teil) | Skriptsuche über Kern, Themes, Ansichten, Admin, Mitglieder, Installer: Fremdhosts nur für Google Fonts, im Theme nur bei Einstellung `google_fonts` (Standard aus) und ohne lokale Schriften (`themes/cms-default/functions.php:100–125, 185–205`); Admin-Schriftverwaltung erlaubt nur `fonts.googleapis.com` und `fonts.gstatic.com` (`FontManagerModule.php:26`). Die CSP erlaubt beide Hosts immer (`Security.php:115–117`, siehe AP-04). | Lizenzen der Bündel (AP-16). |
| 15.6 Tests | ja (Teil) | Alle in diesem Audit neu angelegten Regelprüfungen haben eine Gegenprobe (Mutante, ohne Korrektur rot). Alle 66 Suiten laufen ohne Netz; die Suiten mit Datenbank überspringen sich ohne Variablen. | Zeit- und Zufallsabhängigkeit: `community-performance` war einmal in der CI rot und lokal nie reproduzierbar (Ursache offen). |
| 15.7 CI-Befunde | offen | Nicht geprüft. | Ergebnisse von Semgrep und Trivy bewerten. |

## Abweichungen

### TMP-AP15-1 · Drei leere, versteckte Dateien im Auslieferungsstand (INFO, umgesetzt)

- **Fundstelle:** `CMS/admin/views/security/.in.audit.php`, `CMS/admin/views/seo/.in.analytics.php`, `CMS/admin/views/system/.in.info.php`: je 0 Bytes, von keinem Code eingebunden (Suche nach `.in.`), seit der Zusammenführung #136 versioniert und im Paket ausgeliefert.
- **Korrektur:** Die drei Dateien sind entfernt. Eine Prüfung ist nicht nötig (leere Dateien ohne Verweis).

## Hinweise ohne Änderung

- **INFO-1:** 289 `catch`-Blöcke ohne Behandlung (210 leer, 79 nur Kommentar) sind viele; die meisten schützen Nebenwirkungen (Protokoll, Cache) und sind bewusst leise. Eine Durchsicht sollte die Blöcke um Schreib- und Löschpfade zuerst nehmen.

## Testlauf und Übergabe

Skriptsuchen (`token_get_all`, reguläre Ausdrücke); Gesamtlauf aller Suiten grün, auch mit MariaDB 10.11.

**Offen:** 15.2 bis 15.4, 15.7; PHP 8.4/8.5-Lauf (CI hat 8.4 und 8.5).
