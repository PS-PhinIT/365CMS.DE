# AP-12 – Geschwindigkeit, Skalierung, Missbrauchslast (Version 4.2.12)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank und ohne Browser. Maßstab: [Audit-Katalog, AP-12](../AUDIT-KATALOG-4.2.0.md#ap-12-geschwindigkeit-skalierung-missbrauchslast). Der Bericht betrifft nur AP-12; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund in den gelesenen Teilen (Drosselung, Größenkappung des 404-Monitors, Paginierung). Die meisten Punkte brauchen Datenbank, Lasttest oder Browser und bleiben offen.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 12.1 Abfragen je Seite | offen | Nicht neu geprüft; die Suite `community-performance` legt Abfragebudgets für die Community-Seiten fest. | N+1 und `SELECT *` für Start, Beitrag, Listen, Suche, Admin-Listen auf MariaDB. |
| 12.2 Indizes | offen | Nicht geprüft. Hinweis INFO-1. | `EXPLAIN` der häufigen Abfragen. |
| 12.3 Pro Anfrage | offen | Nicht geprüft (`runSchemaOnce` wird an 12 Stellen verwendet). | Messung von Dateisystem-, Netzwerk- und Sitzungsarbeit je Anfrage. |
| 12.4 Cache | offen | Nicht geprüft; Antwort-Header für Gäste und Angemeldete siehe AP-05 (`Router.php:788–822`). | Schlüssel, Invalidierung, Stampede. |
| 12.5 Missbrauchslast | ja (Stichprobe) | Gedrosselt sind: Suche (`CMS/core/Routing/ThemeRouter.php:195–210`, mit `Retry-After`), Anmeldung und Registrierung (`Auth.php`, `PublicRouter.php:630`, 5 je 15 Minuten), Kommentare (Flood-Limit je Adresse und Konto, `CommentService.php:665–692`), Formulare (`FormSpamGuard`), Newsletter (`NewsletterFrontendController`), Web-Vitals (`ApiRouter`), API-Token-Erneuerung (30 je Stunde, `ApiRouter.php`), Community (`ContentGuard`, `CommunityAccess`). Der 404-Monitor ist auf 20 000 Zeilen begrenzt (`RedirectService.php:27, 207–211`), Aufräumen nach 90 Tagen (`:28, 232–240`). | Eingehende Zahlungs-Webhooks haben keine eigene Drosselung; sie verwerfen unsignierte Aufrufe vor jedem Datenbankzugriff (AP-08). |
| 12.6 Ressourcen | offen (Teil bestätigt) | Bildbomben: AP-06 (Pixelgrenze). | Exporte gestreamt, ReDoS, PDF, `memory_limit`. |
| 12.7 Cron, Queues | offen | Nicht geprüft. | Überlappung, Batchgröße, Backoff. |
| 12.8 Frontend | offen | Nicht geprüft (Lighthouse braucht Browser und laufende Seite). | Assetgröße, Bilder, Schriften, Mobil-Wert. |
| 12.9 Redirect-Manager | ja (Teil) | Paginierung mit 250 Regeln je Seite (`RedirectService.php:32, 260–290`); Treffer im 404-Monitor schreiben nur in `not_found_logs`, nicht in die Regeln. | Kein Hintergrund-Reload: Oberfläche. |

## Abweichungen

Keine.

## Hinweise ohne Änderung

- **INFO-1:** `RedirectService::writeNotFoundLog()` zählt bei jedem neuen Pfad mit `SELECT COUNT(*)` die Tabelle (bis 20 000 Zeilen), bevor sie einfügt. Bei Bot-Wellen mit vielen verschiedenen Pfaden ist das eine zusätzliche Abfrage je 404; ein gecachter Zähler oder ein Index-Zählwert wäre billiger.

## Testlauf und Übergabe

Quelltextlesung und Skriptsuchen; Gesamtlauf aller Suiten grün.

**Offen:** 12.1 bis 12.4, 12.6 bis 12.8 vollständig; Lasttest mit MariaDB.
