# Audit-Zusammenfassung: aktuelle Prüfserie

> **Stand:** 2026-11-23 | **Aktuelle Baseline:** 4.2.0 / Schema v27

## Zeitlinie

| Prüfung | Ergebnis | Heutige Bedeutung |
|---|---|---|
| Audit 2026-10-14 / Folgeaudit | 3.5.0-Sicherheits-, Performance-, Funktions-, Referenz- und GUI-Prüfung | historische Befund- und Fixbasis; OFFEN-14 … 19 umgesetzt |
| Folgeaudit 2026-11-08 | Prüfstand 3.5.6 → 4.0.0 | Vor-Umsetzungs-Snapshot; Hauptbefunde in 4.0.0 behoben |
| Folgeaudit 2026-11-09 | OFFEN-20 … OFFEN-26 nachgeprüft | umgesetzt; nur OFFEN-27 blieb offen |
| Folgeaudit 2026-11-12 | historisches Code-/Changelog-/Dokumentationsprotokoll 4.0 | PR-bezogene Evidenz, kein aktueller Backlog |
| Release-Audit 4.1.0 | Abschluss 4.1-Linie | 1 historisch offener Importpunkt: PERF-410-49 |
| Release 4.2.0 | Schema v27, Community und 4.2-Kernänderungen | noch kein separater vollständiger 4.2-Vollaudit; Nutzung der neuen Ablaufpläne |

## Wirklich offen

1. **OFFEN-27:** Signatur für Core-Updatepakete.
2. **PERF-410-49:** WXR-Import als Web-Request; vor neuer Priorisierung am
   aktuellen Importpfad reproduzieren.

Alle weiteren alten Aufgaben bleiben nur als Evidenz sichtbar. Details:
[Offene Befunde](audits/README.md#offene-befunde).

## 4.2-Prüfschwerpunkt

- Community-Modul: Access, Gate, Counter, Privacy, Queue, Attachments, Keyset-Listen.
- Schema v27: Neuinstallation, Upgrade, Repair, REQUIRED_*-Vertrag.
- lokales Captcha und Form-/Register-/Community-Integration.
- Medien: metadata stripping, EXIF-Orientierung und Alpha.
- Redirect-Manager und Payments/Refund/Zeitzonen.
- Rollen, SSO-Systemrolle `moderator` und neue Hooks.

Die operative Prüfliste ist
[AUDIT-KATALOG-4.2.0.md](AUDIT-KATALOG-4.2.0.md).
