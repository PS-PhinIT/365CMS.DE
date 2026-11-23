# 365CMS Audit-Index

> **Stand:** 2026-11-23 | **Produktstand:** 4.2.0 / Schema v27 (Tag `V4.2.0`) | **Geltung:** Index aller Prüfberichte, Runbooks und der Restliste offener Auditpunkte

> **Archivstatus:** Die Berichte in diesem Bereich dokumentieren die Beweislage zum jeweiligen Prüftag. Sie sind kein
> Backlog. Laufende Arbeit steht in [STATUS.md](../../STATUS.md), den Releasekatalogen
> [v4.2.X](../../v4.2.X/README.md), [v4.3](../../v4.3/README.md), [v4.4](../../v4.4/README.md) und im Abschnitt 4.2.0 des
> [Changelog.md](../../../Changelog.md). Die **einzige** Restliste offener Auditpunkte steht unten unter
> [Offene Befunde](#offene-befunde).

## English (summary)

Index of the 365CMS audit area in `DOC_PLAN/audit/`. The top level holds the reusable runbooks (catalog AP-01…AP-20,
agent protocol, context slices, local-LLM guide, delta and full audit plans) and two summaries. `audits/` holds the
dated follow-up and release audits since November 2026, `audit_2026-10-14/` the full audit of 2026-10-14 with its
partial reports, `changelogs/` the archived 2.x changelog. Only two audit items are still open: OFFEN-27 (signed core
update packages, tracked as 42X-06) and PERF-410-49 (WXR import in a single request).

## Deutsch

### Ordnerstruktur

```text
DOC_PLAN/audit/
├── AUDIT-KATALOG-4.2.0.md            Was geprüft wird (AP-01 … AP-20)
├── AUDIT-AGENT-PROTOKOLL.md          Wie ein Agent/Modell einen Lauf ausführt
├── AUDIT-SLICES.md                   Aufteilung des Codes in Kontext-Slices
├── AUDIT-LOCAL-LLM.md                Lokale Modelle (AMD Radeon AI PRO R9700, 32 GB)
├── AUDIT-ABLAUF-PLAN-DELTA.md        Runbook Delta-Audit seit letztem Release
├── AUDIT-ABLAUF-PLAN-VOLLAUDIT.md    Runbook Vollaudit
├── AUDIT-ZUSAMMENFASSUNG-AKTUELL.md  Verdichteter Stand 2026-10 bis 4.2.0
├── AUDIT-ZUSAMMENFASSUNG-LEGACY.md   Verdichtete Alt-Audits bis 3.5.0
├── audits/                           Datierte Prüfberichte ab 2026-11 + dieser Index
├── audit_2026-10-14/                 Gesamtaudit 2026-10-14 mit Teilberichten
├── changelogs/                       Archivierter 2.x-Changelog
└── runs/<run-id>/                    Arbeitsordner laufender Audits (entsteht erst beim Lauf)
```

### Offene Befunde

Ein unerledigtes Häkchen in einem historischen Bericht zählt **nicht** als offener Punkt. Offen sind nur:

| Audit-ID | Status | Einordnung | Nächster Nachweis |
|---|---|---|---|
| OFFEN-27 | offen | Signierte Core-Updatepakete; SHA-256 allein schützt nicht gegen eine kompromittierte Update-Quelle | [v4.2.X 42X-06](../../v4.2.X/CATALOG.md#42x-06--signierte-update-pakete) |
| PERF-410-49 | offen, historisch | WXR-Import läuft in einem Request; im 4.1-Audit akzeptiert, kein 4.2.0-Releaseblocker | aktuellen Importpfad reproduzieren, dann in den passenden Katalog aufnehmen; siehe [Release-Audit 4.1.0](RELEASEAUDIT-2026-11-15_4.1.0.md) |

Abgeschlossen und nur noch als Nachweis geführt:

- OFFEN-01 … OFFEN-13 (Gesamtaudit 2026-10-14) und OFFEN-14 … OFFEN-19 (Folgeaudit 2026-10-14): umgesetzt in 3.5.0.
- OFFEN-20 … OFFEN-26 (Folgeaudit 2026-11-08): umgesetzt in 4.0.0, am 2026-11-09 erneut geprüft.
- Alle übrigen IDs mit Status `✅`: behobene historische Befunde.
- Begriffe aus entfernten Altberichten: erst nach aktuellem Reproduktionsfall wiedereröffnen.

Releasezuordnung neuer Befunde: Regression, Sicherheitsnacharbeit, Upgrade-/Paketproblem → 4.2.x; CLI, Alerting,
Betrieb → 4.3; Custom Fields, N-Sprachen, Live-Vorschau, Managed API → 4.4; Community P2/P3 nur nach Priorisierung
in [STATUS.md](../../STATUS.md).

### Runbooks für künftige Audits

| Dokument | Inhalt |
|---|---|
| [AUDIT-KATALOG-4.2.0.md](../AUDIT-KATALOG-4.2.0.md) | Prüfkatalog AP-01 bis AP-20 mit nummerierten Prüfpunkten, Einstiegsdateien, Test-Suiten, Such-Rezepten und bewussten Entscheidungen |
| [AUDIT-AGENT-PROTOKOLL.md](../AUDIT-AGENT-PROTOKOLL.md) | modellunabhängiges Ausführungsprotokoll: Laufordner, Ledger, Befundformat (Markdown/JSONL), Prompts P0–P7, Modellprofile Claude/Gemini/Codex |
| [AUDIT-SLICES.md](../AUDIT-SLICES.md) | Aufteilung des Codes in Kontext-Slices ≤ 150k Token mit AP-Zuordnung |
| [AUDIT-LOCAL-LLM.md](../AUDIT-LOCAL-LLM.md) | Audit mit lokalen Modellen auf AMD Radeon AI PRO R9700 (32 GB, 256k Kontext), Empfehlungen je Auditbereich |
| [AUDIT-ABLAUF-PLAN-DELTA.md](../AUDIT-ABLAUF-PLAN-DELTA.md) | Delta-Audit seit dem letzten Release-Tag, Phasen D0–D7 |
| [AUDIT-ABLAUF-PLAN-VOLLAUDIT.md](../AUDIT-ABLAUF-PLAN-VOLLAUDIT.md) | Vollaudit, Phasen V0–V8 mit Slice-Wellen und Laufzeitprüfungen |

### Zusammenfassungen

| Dokument | Inhalt |
|---|---|
| [AUDIT-ZUSAMMENFASSUNG-AKTUELL.md](../AUDIT-ZUSAMMENFASSUNG-AKTUELL.md) | konsolidierter Stand der Prüfreihen 2026-10 bis 4.2.0 |
| [AUDIT-ZUSAMMENFASSUNG-LEGACY.md](../AUDIT-ZUSAMMENFASSUNG-LEGACY.md) | konsolidierte Alt-Audits; ersetzt die entfernten Rohlisten und Juni-Audits |

### Prüfberichte (chronologisch)

**Laufendes Audit:** [AP-01 – Anmeldung und Sitzung, 23.11.2026](../audit_2026-11-23/FOLGEAUDIT-AP-01-2026-11-23.md), [AP-02 – Autorisierung, Rollen, Objektzugriff, 23.11.2026](../audit_2026-11-23/FOLGEAUDIT-AP-02-2026-11-23.md) und [AP-03 – Eingaben: Injection, Traversal, SSRF, 23.11.2026](../audit_2026-11-23/FOLGEAUDIT-AP-03-2026-11-23.md). Die Teilberichte trennen statische Befunde von offenen Laufzeitprüfungen; sie sind noch keine Release-Abnahme.

| Datum | Dokument | Stand | Inhalt |
|---|---|---|---|
| 2026-10-14 | [audit_2026-10-14/GESAMTAUDIT-2026-10-14.md](../audit_2026-10-14/GESAMTAUDIT-2026-10-14.md) | 3.5.0 | Gesamtaudit: 54 Befunde (SEC-16 … SEC-27, PERF-07 … PERF-11, LAST-01 … LAST-06, FUN-21 … FUN-25, REF-06 … REF-11, GUI-01 … GUI-07), Empfehlungen OFFEN-01 … OFFEN-13; behoben in 3.4.25/3.5.0 |
| 2026-10-14 | [audit_2026-10-14/FOLGEAUDIT-PRUEFKATALOG-2026-10-14.md](../audit_2026-10-14/FOLGEAUDIT-PRUEFKATALOG-2026-10-14.md) | 3.5.0 | Teilbericht Prüfkatalog und Methodik |
| 2026-10-14 | [audit_2026-10-14/FOLGEAUDIT-SICHERHEIT-2026-10-14.md](../audit_2026-10-14/FOLGEAUDIT-SICHERHEIT-2026-10-14.md) | 3.5.0 | Teilbericht Sicherheit (SEC-16 … SEC-27) |
| 2026-10-14 | [audit_2026-10-14/FOLGEAUDIT-PERFORMANCE-2026-10-14.md](../audit_2026-10-14/FOLGEAUDIT-PERFORMANCE-2026-10-14.md) | 3.5.0 | Teilbericht Geschwindigkeit und Massenanfragen (PERF-07 … PERF-11, LAST-01 … LAST-06) |
| 2026-10-14 | [audit_2026-10-14/FOLGEAUDIT-FUNKTIONEN-2026-10-14.md](../audit_2026-10-14/FOLGEAUDIT-FUNKTIONEN-2026-10-14.md) | 3.5.0 | Teilbericht Funktionen und Verweise (FUN-21 … FUN-25, REF-06 … REF-11) |
| 2026-10-14 | [audit_2026-10-14/FOLGEAUDIT-BARRIEREFREIHEIT-2026-10-14.md](../audit_2026-10-14/FOLGEAUDIT-BARRIEREFREIHEIT-2026-10-14.md) | 3.5.0 | Teilbericht GUI und Barrierefreiheit (GUI-01 … GUI-07) |
| 2026-10-14 | [audit_2026-10-14/Readme.md](../audit_2026-10-14/Readme.md) | 3.5.0 | Folgeaudit am selben Tag: 43 Befunde, Empfehlungen OFFEN-14 … OFFEN-19; alle in 3.5.0 umgesetzt |
| 2026-11-08 | [FOLGEAUDIT-2026-11-08.md](FOLGEAUDIT-2026-11-08.md) | 3.5.6 → 4.0.0 | Vor-Umsetzungsstand: SEC-32 … SEC-47, DEP-01 … DEP-04, FUN-35 … FUN-54, BP-06 … BP-09; OFFEN-20 … OFFEN-27 |
| 2026-11-09 | [FOLGEAUDIT-2026-11-09.md](FOLGEAUDIT-2026-11-09.md) | 4.0.0 | Umsetzung OFFEN-20 … OFFEN-26, Regressionsprüfung (SEC-48, FUN-55 … FUN-62, BP-10); OFFEN-27 bleibt offen |
| 2026-11-12 | [FOLGEAUDIT-2026-11-12.md](FOLGEAUDIT-2026-11-12.md) | 4.0.0 | Prüfprotokoll Code, Sicherheit, Architektur: Changelog-Einträge CL-001 … CL-233, Prüfbereiche je Pull Request |
| 2026-11-15 | [RELEASEAUDIT-2026-11-15_4.1.0.md](RELEASEAUDIT-2026-11-15_4.1.0.md) | 3.5.0 → 4.1.0 | Release- und Code-Abnahme (Schema v23 → v26), Bereiche SEC-410, DB-410, FUN-410, PERF-410, DOC-410; 145 Befunde, 136 behoben, 4 verworfen, 3 akzeptiert, 1 dokumentiert, 1 offen (PERF-410-49) |

### Weitere Unterlagen

| Dokument | Inhalt |
|---|---|
| [changelogs/CHANGELOG-OLD.md](../changelogs/CHANGELOG-OLD.md) | archivierter 2.x-Changelog bis 2.9.788; kein Backlog |
| [RELEASE/README-4.2.0.md](../../../RELEASE/README-4.2.0.md) | Paketinhalt und Update-Hinweise des freigegebenen 4.2.0-Standes |
| [DOC/core/SECURITY.md](../../../DOC/core/SECURITY.md) | aktuelle Sicherheitsarchitektur (Referenz für AP-01 … AP-07) |

### Namensregeln für neue Berichte

| Art | Ablageort und Name |
|---|---|
| Delta-Audit nach [Runbook](../AUDIT-ABLAUF-PLAN-DELTA.md) | `audits/AUDIT-DELTA-<YYYY-MM-DD>.md` |
| Vollaudit nach [Runbook](../AUDIT-ABLAUF-PLAN-VOLLAUDIT.md) | `audits/AUDIT-VOLLAUDIT-<YYYY-MM-DD>.md` |
| Folgeaudit/Nachprüfung | `audits/FOLGEAUDIT-<YYYY-MM-DD>.md` |
| Release-Abnahme | `audits/RELEASEAUDIT-<YYYY-MM-DD>_<Version>.md` |
| Audit mit mehreren Teilberichten | eigener Ordner `audit_<YYYY-MM-DD>/` mit Hauptbericht und `FOLGEAUDIT-<THEMA>-<YYYY-MM-DD>.md` |
| Arbeitsstand eines laufenden Audits | `runs/<YYYY-MM-DD>-<delta|voll>/` (siehe [Protokoll §3](../AUDIT-AGENT-PROTOKOLL.md#3-laufordner-und-ledger)) |

Nach jedem neuen Bericht: Zeile in „Prüfberichte“ ergänzen, [Offene Befunde](#offene-befunde) fortschreiben und
[AUDIT-ZUSAMMENFASSUNG-AKTUELL.md](../AUDIT-ZUSAMMENFASSUNG-AKTUELL.md) aktualisieren.

### ID-Präfixe

`SEC` Sicherheit · `PERF` Leistung · `LAST` Massenanfragen/Bots · `FUN` Funktionen · `REF` Verweise · `GUI`
Oberfläche · `BP` Best Practice · `DEP` Abhängigkeiten · `DB` Datenbank · `DOC` Dokumentation · `CL` Changelog-Prüfung ·
`OFFEN` Empfehlung. Ab 4.2.0 gilt das Schema `<BEREICH>-<RELEASE>-<NR>` aus dem
[Katalog §3.2](../AUDIT-KATALOG-4.2.0.md#32-id-schema). Historische IDs behalten ihre Bedeutung; ihr heutiger Status
steht nur unter [Offene Befunde](#offene-befunde) oder in den Releasekatalogen.
