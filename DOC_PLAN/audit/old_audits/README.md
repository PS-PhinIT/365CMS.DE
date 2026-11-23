# Historische Auditunterlagen

<!-- AUDIT-ARCHIVE-CLASSIFIED-2026-11-23 -->
> **Historischer Altbestand:** Diese Dateien dokumentieren Prüfstände bis 2026-06-10
> sowie ältere Checklisten. Sie sind weder aktuelle Produktangaben noch ein
> Aufgaben-Backlog. Maßgeblich: [OPEN-FINDINGS.md](../OPEN-FINDINGS.md),
> [../STATUS.md](../../STATUS.md) und die Releasekataloge.

## Reifegrad der Unterlagen

| Gruppe | Dateien | Nutzung |
|---|---|---|
| technische Prüfung Juni 2026 | `365cms-technical-audit-report.md`, `AUDIT_core_2026-06-10.md`, `AUDIT_admin_2026-06-10.md`, `AUDIT_member-includes-views_2026-06-10.md` | historische Code-/Bewertungsbasis; Scores nicht als aktuellen Stand lesen |
| konsolidierte Audits April | `Audit-Backlog.md`, `Audit-Content-Platform.md`, `Audit-Design-Media.md`, `Audit-Live-External.md`, `Audit-System-Security.md`, `Audit-Users-Commerce.md` | alte Befundmengen und Prioritäten; nur nach Reproduktion übernehmen |
| Bewertungen/Checklisten | `BEWERTUNG.md`, `Baukasten-CHECKLISTE.md`, `MustHave-CHECKLISTE.md`, `NiceToHave-CHECKLISTE.md`, `ToDoPrüfung.md` | historische Produkt-/Qualitätskriterien, keine aktuellen Task-Marker |
| Themenprüfungen | `ai-ki-module.md`, `bugs-stabilitaet.md`, `funktionsvollstaendigkeit.md`, `performance.md`, `seo.md`, `sicherheit.md`, `unvollstaendige-implementierungen.md`, `wartbarkeit.md` | wiederholbare Prüfdimensionen, aber veralteter Ist-Stand |
| Begleitdaten | `365cms-technical-audit-report.html`, `dependency-governance.json`, `.last-audit-run` | Beweisdokumente; nicht als aktuelle Version oder aktuelle Prüfausführung ausgeben |

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
