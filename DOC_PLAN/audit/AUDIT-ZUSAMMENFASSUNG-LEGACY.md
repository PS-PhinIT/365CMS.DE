# Audit-Zusammenfassung: historische Altbestände

> **Stand:** 2026-11-23 | **Geltung:** Archiv, keine aktuelle Befundliste

## Erhaltene Beweisdateien

Die drei detaillierten Code-Audits bleiben unter `old_audits/`:

- `AUDIT-CORE-2026-06-10.md`
- `AUDIT-ADMIN-2026-06-10.md`
- `AUDIT-MEMBER-INCLUDES-VIEWS-2026-06-10.md`

Sie dokumentieren getrennte Teilbereiche und sind auf den damaligen Stand
3.3.47 bezogen. Ihre Scores, Fundzahlen und Empfehlungen sind keine aktuelle
Freigabeaussage.

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
[OFFENE-BEFUNDE.md](OFFENE-BEFUNDE.md).
