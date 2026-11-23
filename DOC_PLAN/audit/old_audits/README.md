# Historische Auditunterlagen

<!-- AUDIT-ARCHIVE-CLASSIFIED-2026-11-23 -->
> **Historischer Altbestand:** Diese Dateien dokumentieren Prüfstände bis 2026-06-10
> sowie ältere Checklisten. Sie sind weder aktuelle Produktangaben noch ein
> Aufgaben-Backlog. Maßgeblich: [OFFENE-BEFUNDE.md](../OFFENE-BEFUNDE.md),
> [../STATUS.md](../../STATUS.md) und die Releasekataloge.

## Erhaltene Unterlagen

| Gruppe | Dateien | Nutzung |
|---|---|---|
| detaillierte Code-Audits Juni 2026 | `AUDIT-CORE-2026-06-10.md`, `AUDIT-ADMIN-2026-06-10.md`, `AUDIT-MEMBER-INCLUDES-VIEWS-2026-06-10.md` | historische Teilprüfungen; Scores und Fundstatus nicht als aktuellen Stand lesen |
| konsolidierte Zusammenfassung | [AUDIT-ZUSAMMENFASSUNG-LEGACY.md](../AUDIT-ZUSAMMENFASSUNG-LEGACY.md) | Themen, wiederzuprüfende Residuen und Zuordnung zu 4.2.X/4.3/4.4 |

Die früheren Rohlisten, Bewertungs-, Live-, Checklisten-, Fach- und HTML-/JSON-
Begleitdateien wurden nach der Konsolidierung entfernt. Ihre Erkenntnisse sind
in der Legacy-Zusammenfassung nach Themen gruppiert.

## Regel für die Wiederverwendung

1. konkreten Befund aus dem Altbericht lokalisieren;
2. an aktuellem `CMS/`, `TESTS/` und `Changelog.md` reproduzieren;
3. Auswirkung und Releasegrenze benennen;
4. erst dann 4.2.X, 4.3, 4.4 oder Community-Roadmap aktualisieren;
5. den historischen Audittext nicht nachträglich als erledigt überschreiben.

## Nicht mehr vorhandene Quellen

Alte Verweise auf `Snyk_Audit_04042026.md`, `LiveAudit_365CMS.md` oder weitere
frühere Auditpfade bezeichnen Quellen, die im aktuellen Repository nicht enthalten
sind. Sie werden nicht künstlich nachgebaut und gelten nicht als offene Dateien.
