# AP-16 – Drittbibliotheken und Lieferkette (Version 4.2.16)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank. Maßstab: [Audit-Katalog, AP-16](../AUDIT-KATALOG-4.2.0.md#ap-16-drittbibliotheken-und-lieferkette). Der Bericht betrifft nur AP-16; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund in den gelesenen Teilen (Workflow, externe Aufrufe der Bundles). Inventar, Abgleich mit Upstream und Parser-Bibliotheken sind nicht geprüft.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 16.1 Inventar | offen | Nicht geprüft; SunEditor wird in der Suite `security` gegen die dokumentierte SHA-256 geprüft (`DOC/assets/suneditor/README.md`). | Name, Version, Herkunft, Lizenz, Prüfsumme und Trivy-Stand aller Bibliotheken. |
| 16.2 Bundles | offen | Nicht geprüft. | Abgleich gegen Upstream. |
| 16.3 Browser-Bibliotheken | ja (Stichprobe) | Skriptsuche über `CMS/assets/` (JS, CSS): keine festen Aufrufe externer Hosts; die einzigen Treffer sind URL-Muster der Editor.js-Einbettung (`editorjs/embed.umd.js`) und Sprachtexte von SunEditor. | Verhalten unter Trusted Types im Browser. |
| 16.4 Parser | offen | Nicht geprüft (Archivprüfer siehe AP-03 und AP-06). | XML (XXE), YAML, Markdown, PDF. |
| 16.5 Workflow | ja (Teil) | `.github/workflows/security.yml`: `permissions: contents: read`, alle vier Actions auf Commit-SHA gepinnt, kein `pull_request_target`, keine Secrets, keine Einbindung von Anfrage-Text in `run:`; Trivy bricht bei HIGH/CRITICAL ohne Korrektur ab (`exit-code: '1'`, `ignore-unfixed: true`), LOW/MEDIUM nur als Bericht. | Abgleich Manifest ↔ Bundle ↔ Doku über SunEditor hinaus. |

## Abweichungen

Keine.

## Hinweise ohne Änderung

- **INFO-1:** Der Semgrep-Schritt nutzt `returntocorp/semgrep-action` (gepinnt). Die Action wurde vom Anbieter umbenannt und gilt als veraltet; der Wechsel auf den Container-Aufruf ist eine spätere Wartungsaufgabe.
- **INFO-2:** Das Vollpaket enthält einen Test einer Fremdbibliothek (siehe AP-09, INFO-2).

## Testlauf und Übergabe

Skriptsuchen, keine Ausführung des Workflows. Gesamtlauf aller Suiten grün.

**Offen:** 16.1, 16.2, 16.4 und die Prüfsummen der übrigen Bundles.
