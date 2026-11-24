# AP-11 – Community-Modul (Version 4.2.11)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe; erstmals mit MariaDB 10.11 für die Suiten. Maßstab: [Audit-Katalog, AP-11](../AUDIT-KATALOG-4.2.0.md#ap-11-community-modul). Der Bericht betrifft nur AP-11; er ist keine Freigabe des gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Keine eigene Durchsicht des Moduls in diesem Lauf. Mit echter Datenbank laufen alle 24 `community-*`-Suiten grün; ein Datenschutz-Punkt wurde mit Messung bestätigt.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 11.1 Rechte | offen | Suite `community-access` grün (MariaDB). | Nebenwege (Suche, Sitemap, Feeds, Zitate, Mails, Export …) im Einzelnen. |
| 11.2 404 statt 403 | offen | Suiten `community-access`, `community-search-seo` grün. | Zeit- und Längenunterschiede. |
| 11.3 Sanktionen, Raten | offen | Suiten `community-sanctions`, `community-moderation` grün. | Parallele Anfragen über `/forum/api/*`. |
| 11.4 Schreibwege | offen | Suiten `community-captcha`, `community-spam`, `community-content` grün. | Vollständigkeit vor jedem Schreiben (Verschieben). |
| 11.5 Zähler | offen | Suiten `community-db`, `community-repositories` grün. | Abgleich Zählen gegen Summen. |
| 11.6 Datenschutz | ja (Teil) | Löschung mit allen Hooks auf MariaDB gemessen (siehe AP-10, TMP-AP10-2): Community-Tabellen werden bis auf `community_reactions` (Reaktion ohne Beitrag, INFO) geleert; Export und Aufbewahrung in `CommunityPrivacy`, `CommunityRetention`; Suite `community-privacy` grün. | `erase_mode` anonymize/delete im Einzelnen, `ip_hash` nie Klartext. |
| 11.7 Benachrichtigungen | offen | Suite `community-notify` grün. | Mail-Bombing, Rechte zum Zustellzeitpunkt. |
| 11.8 Suche | offen | Suite `community-search-seo` grün. | Rechtefilter vor Treffern, Drosselung. |
| 11.9 JSON-API | offen | Suite `community-api` grün. | Idempotenz, Größen. |
| 11.10 Anhänge | offen | Suite `community-uploads` grün (Pixelgrenze: AP-06). | Verwaiste Uploads, Sandbox-CSP im Browser. |
| 11.11 Moderation | offen | Suite `community-moderation` grün. | Pflichtgründe, Massenaktionen. |
| 11.12 Frontend | offen | Suiten `community-xss`, `community-render`, `community-frontend`, `community-a11y` grün. | Browser-Korpus. |
| 11.13 Leistung | offen | Suite `community-performance` grün (Abfragebudget, EXPLAIN gegen MariaDB). | Große Foren. |
| 11.14 Modul aus | offen | Suite `community-admin` grün. | Provider bei abgeschaltetem Modul. |
| 11.15 Umfragen | offen | Nicht geprüft (Katalog fordert Ende-zu-Ende-Stand, nicht Spekulation). | – |

## Abweichungen

Keine.

## Testlauf und Übergabe

Alle 24 Suiten `community-*` einzeln im Gesamtlauf mit MariaDB 10.11: 0 fehlgeschlagen. Die Suiten decken die Prüfpunkte weitgehend ab (mit Mutanten); dieser Bericht ersetzt keine Durchsicht der 15 Punkte.

**Offen:** alle Punkte außer 11.6 (Teil) für eine eigene Durchsicht; Browser-Prüfung.
