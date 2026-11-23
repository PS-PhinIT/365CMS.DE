# Audit-Zusammenfassung: historische Altbestände

> **Stand:** 2026-11-23 | **Geltung:** Archiv, keine aktuelle Befundliste

## Erhaltene Beweisdateien

Als Einzeldateien erhalten ist nur das Gesamtaudit 2026-10-14 im Ordner `audit_2026-10-14/`
(Index: [audits/README.md](audits/README.md)):

- Hauptbericht: [GESAMTAUDIT-2026-10-14.md](audit_2026-10-14/GESAMTAUDIT-2026-10-14.md), Folgeaudit desselben Tages:
  [Readme.md](audit_2026-10-14/Readme.md)
- Teilberichte: [Sicherheit](audit_2026-10-14/FOLGEAUDIT-SICHERHEIT-2026-10-14.md),
  [Geschwindigkeit/Last](audit_2026-10-14/FOLGEAUDIT-PERFORMANCE-2026-10-14.md),
  [Funktionen/Verweise](audit_2026-10-14/FOLGEAUDIT-FUNKTIONEN-2026-10-14.md),
  [GUI/Barrierefreiheit](audit_2026-10-14/FOLGEAUDIT-BARRIEREFREIHEIT-2026-10-14.md),
  [Prüfkatalog/Methodik](audit_2026-10-14/FOLGEAUDIT-PRUEFKATALOG-2026-10-14.md)

Die detaillierten Code-Audits vom 2026-06-10 (`core/`, `admin/`, `member/`/`includes/`/`views/`, Stand 3.3.47) sowie
die früheren Rohlisten wurden entfernt; ihre Themen sind in der Tabelle unten verdichtet. Die Oktober-Berichte beziehen
sich auf 3.5.0. Scores, Fundzahlen und Empfehlungen aller Altberichte sind keine aktuelle Freigabeaussage.

## Zusammengefasster Altinhalt

| Altbereich | Wiederkehrende Themen | Heutige Behandlung |
|---|---|---|
| Core-/Admin-/Member-Audit Juni | Fehlerpfade, Rechte, Superglobals, Session/Auth, Struktur | detaillierte Datei bleibt; neue Claims nur mit aktuellem Test |
| technischer April-/Juni-Bericht | Scores, Backup/Restore, Runtime-Guards, Dependency-Drift | Bewertung archiviert; mögliche Lücke nur nach Reproduktion |
| Backlog/Content/Design/Commerce | funktionale Lücken, Menüs, Medien, Benutzer und Zahlungen | 4.2-Status aus Code/Changelog/Releasekatalog, keine offene Checkbox übernehmen |
| Live/External | alte Live-Sites, Snyk-/Produktionssnapshots | historische Quelle; fehlende Quelldateien werden nicht rekonstruiert |
| Must/Nice/Baukasten/ToDo | große Wunsch- und Checklisten | keine verbindliche Roadmap; Kriterien erst in 4.2.X/4.3/4.4 übernehmen |
| Fachthemen AI/Sicherheit/SEO/Performance/Wartbarkeit | Prüfideen und alte Ist-Bewertungen | als AP im aktuellen Katalog wiederholbar, nicht als aktueller Befund |

## Kandidaten, die nur nach Reproduktion wiedereröffnet werden

- persistenter Backup-Fortschritt bzw. Resume;
- strikte physische Trennung von Quell-, Audit- und Runtime-Artefakten;
- restliche Superglobal-/Service-Kapselung;
- einzelne unvollständige UI-/Feld-/Doku-Aussagen aus April/Juni.

Keiner dieser Punkte ist allein wegen eines Alt-Hakens offen. Er muss mit aktuellem
Code, Test und Releasekontext belegt werden.

## Ersetzte Altdateien

Die getrennten Rohlisten für Backlog, Live, Design, Commerce, Content, Security,
Bewertung, To-do, Must-have, Nice-to-have, AI, Bugs, Performance, SEO,
Wartbarkeit und unvollständige Implementierungen wurden nach dieser
Zusammenfassung entfernt. Ihre historischen Erkenntnisse leben hier als Themen-
und Prüfhinweise weiter; die drei Juni-Code-Audits bleiben als detaillierte
Evidence erhalten.

## Übergang in aktuelle Pläne

- 4.2.x: Regression, Security, Paket/Upgrade.
- 4.3: CLI, Alerting, Betrieb.
- 4.4: Custom Fields, N-Sprachen, Live Preview, Managed API.
- Community: vorhandene P2/P3-Roadmap.

Maßgeblich sind [../STATUS.md](../STATUS.md) und
[Offene Befunde](audits/README.md#offene-befunde).
