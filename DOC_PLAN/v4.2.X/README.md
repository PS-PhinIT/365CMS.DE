# 365CMS 4.2.x: Patch- und Wartungskatalog

> **Stand:** 2026-11-23 | **Baseline:** 4.2.0 / Schema v27 | **Zweck:** ausschließlich 4.2.x

`v4.2.X/` ist die Sammelstelle für **Regressionskorrekturen, Sicherheitsnacharbeiten und
abgrenzbare Abnahmereste der 4.2-Linie**. Neue Funktionen, Schemastruktur-Änderungen und
Architekturvorhaben gehören nach [`../v4.3/`](../v4.3/README.md).

## Aufnahmegrenzen

Ein Punkt gehört in 4.2.x, wenn mindestens eine der folgenden Bedingungen erfüllt ist:

1. produktiver 4.2.0-Code verletzt einen dokumentierten Vertrag;
2. ein reproduzierbarer Sicherheits-, Daten-, Rechte- oder Upgrade-Fehler besteht;
3. ein ausdrücklich offener P1-Abnahmeschritt ohne neue Produktfunktion nachholbar ist;
4. die Änderung mit v27, bestehenden APIs, Themes und Plugins rückwärtskompatibel ist.

Nicht hierher gehören neue Inhaltstypen, beliebig viele Sprachen, eine zentrale CLI,
konzeptionell neue Integrationen oder Community-P2/P3. Dafür ist
[der 4.3-Katalog](../v4.3/CATALOG.md) maßgeblich.

## Status und Priorität

- `MUST` – Blocker für 4.2.x, Sicherheitsrelevanz oder verpflichtende 4.2-Abnahme
- `SHOULD` – wichtiger, eng begrenzter Fehlstand oder Kompatibilitätsnacharbeit
- `COULD` – nur wenn reproduziert und ohne Schema-/API-Risiko
- `VERIFY` – Zustand zuerst am Code nachweisen; erst danach in Bug oder erledigt einordnen
- `[ ]` offen, `[~]` wird geprüft, `[x]` abgeschlossen, `[-]` verworfen

## Dokumente

| Dokument | Inhalt |
|---|---|
| [CATALOG.md](CATALOG.md) | geordneter Katalog aller 4.2.x-Kandidaten |
| [VALIDATION.md](VALIDATION.md) | Eintrittskriterien, Tests und Release-Gates |
| [SOURCES.md](SOURCES.md) | lokale Evidenz und Abgrenzung gegenüber 4.3 |

## Verbindliche Regel

Kein Eintrag wird allein wegen eines entfernten Wunschzettels oder eines Vergleichs mit
anderen CMS in 4.2.x verschoben. Jede Korrektur braucht eine konkrete 4.2.0-Abweichung,
einen reproduzierbaren Befund oder einen explizit dokumentierten P1-Abnahmerest.
