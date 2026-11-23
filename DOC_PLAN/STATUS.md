# 365CMS Planungsstand

> **Stand:** 2026-11-23 | **Aktueller Release:** 4.2.0 | **Aktuelles Schema:** v27

Dieses Dokument ist die zentrale Statusquelle für alle Pläne und Spezifikationen unter `DOC_PLAN/`.
Historische Entwurfs-, PR- und Zielversionsangaben in den Unterlagen beschreiben ihren damaligen Stand;
sie dürfen nicht als aktueller Auslieferungsstatus gelesen werden.

## Gesamtstatus

| Bereich | Status am 23.11.2026 | Nächster Umfang |
|---|---|---|
| Operative Workflows | ✅ auf 4.2.0 abgeglichen | Nur bei Produktänderungen fortschreiben |
| Admin-Menü/Profile | ✅ ausgeliefert | Regressionsprüfung bei neuen Admin-Seiten |
| Integrierte Plugins | ✅ 4.0.0-Parität hergestellt | Abweichungen nur als neue Anforderung behandeln |
| v4.1 Bereiche 1–5 | ✅ in 4.1.0 ausgeliefert | v26 bleibt historische Schema-Basis |
| v4.1 Bereich 6: CLI/Alerting | 🟡 für 4.3.0 eingeplant | Zentrale CLI und `alert.raised` |
| Community P1 | ✅ in 4.2.0 ausgeliefert, Schema v27 | Fehlerbehebungen und Abnahme fortführen |
| Community P2/P3 | 🟡 offen | Siehe Community-Roadmap |
| Freigabe-Ablauf (Inhaltsplattform A) | ✅ in 4.1.0 ausgeliefert | Wartung und Erweiterung |
| Felder/Typen (Inhaltsplattform B) | 🔴 für 4.4.0 eingeplant | Custom-Field- und Typ-Vertrag freigeben |
| Mehrsprachigkeit (Inhaltsplattform C) | 🔴 für 4.4.0 eingeplant | Architektur erst nach B |
| Live Editor Preview | 🔴 für 4.4.0 eingeplant | CSP-sichere Split-View im Editor |
| Managed Public API | 🔴 für 4.4.0 eingeplant | Client Credentials/API-Keys, Scopes und OpenAPI |

## Noch offene Punkte

### Release 4.3.0

- [ ] Zentrale CLI `CMS/cli.php` mit sicher freigegebenen Unterbefehlen.
- [ ] Echtzeit-Alerting mit Hook `alert.raised`.
- [ ] Betriebs- und Berechtigungskonzept für CLI und Alert-Kanäle.
- [ ] Automatisierte Tests, Dokumentation und Release-Nachweise für beide Punkte.

### Inhaltsplattform

- [ ] Teil B: Feldgruppen und wiederverwendbare Feldtypen für bestehende Inhaltstypen.
- [ ] Teil B: Eigene Inhaltstypen mit Editor, Routing, Vorlagen, Papierkorb, Suche und Austausch.
- [ ] Teil B: Offene Entscheidungen zu Code-/UI-Definitionen und Kommentaren festlegen.
- [ ] Teil C: Sprachmodell und Migration von festen `*_en`-Spalten auf beliebige Sprachen.
- [ ] Teil C: Sprachverwaltung, Routing, Sitemaps, Suche und Übersetzungsstatus je Sprache.
- [ ] Teil C: Funktionsbereiche Formulare, Downloads, Knowledgebase, Newsletter und Feeds migrieren.

Diese Punkte sind für 4.4 in [v4.4/README.md](v4.4/README.md) zusammengeführt.

### Release 4.4.0

- [ ] Custom Fields für Bestands- und eigene Inhaltstypen.
- [ ] N-Sprachen-Modell mit DE/EN-Kompatibilität.
- [ ] Live-Splitscreen-Vorschau im Redaktionseditor.
- [ ] Managed Public REST API mit Client Credentials/API-Keys und OpenAPI.

### Community nach P1

- [ ] P2: Umfragen, ähnliche Themen, E-Mail-Digests, Erwähnungen, Lesezeichen und Ignorieren/Online-Status.
- [ ] P2: Verwarnungsoberfläche sowie Zusammenführen/Teilen von Themen.
- [ ] P3: Erweiterungen aus dem Community-Plan, nach gesonderter Priorisierung.
- [ ] Integration in das externe `cms-phinit`-Theme und Prüfung gegen das reale Theme.
- [ ] Lasttest mit realistisch großer MariaDB- bzw. MySQL-Datenmenge.
- [ ] MariaDB-Prüfung in der CI sowie manuelles Screenreader-Sampling dokumentieren.

## Statusregeln

- `✅ ausgeliefert` bedeutet im bezeichneten Release vorhanden; offene Folgearbeiten bleiben separat offen.
- `🟡 offen/verschoben` bedeutet geplant, aber nicht Bestandteil des letzten Release.
- `🔴 nicht begonnen` bedeutet: keine produktive Teilimplementierung vorhanden.
- Historische Dokumente behalten ihre damalige Zielversion, kennzeichnen diese aber ausdrücklich als Baseline.
- Feature-Matrizen beschreiben Anforderungen und Plugin-Parität; ihre letzte Spalte ist nur mit expliziter Kennzeichnung ein Core-Lieferstatus.

## Detailquellen

- [v4.1 Übersicht](v4.1/README.md) und [v4.1 Fortschritt](v4.1/PROGRESS.md)
- [Community-Roadmap](v4.2/ROADMAP.md) und [Community-Übersicht](v4.2/README.md)
- [Roadmap Inhaltsplattform](ROADMAP-CONTENT-PLATFORM.md)
- [Release 4.3](v4.3/README.md)
- [Release 4.4](v4.4/README.md)
- [Admin-Menü-Profile](ADMIN-MENU-PROFILE.md)
