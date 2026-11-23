# 365CMS 4.3: Releasekatalog

> **Stand:** 2026-11-23 | **Ausgangsbasis:** 4.2.0 / Schema v27 | **Zielversion:** 4.3.0

Dieses Verzeichnis bündelt den fachlich vollständigen Kandidatenkatalog für 4.3.0.
Es ist **kein Zuschnitt- oder Lieferzuspruch**: Einträge ohne verbindliche Entscheidung
bleiben `PROPOSED`, bis Nutzen, Architektur, Migration und Tests freigegeben sind.

## Verbindlicher Kern

Aus den bestehenden Plänen sind für 4.3 bereits verbindlich angelegt:

1. zentrale CLI;
2. Echtzeit-Alerting mit sicheren Kanälen und `alert.raised`;
3. gemeinsamer Preflight-/Worker-Vertrag für CLI, Cron und Admin;
4. optionale, unabhängige Community-P2-Nacharbeiten.

Custom Fields, beliebig viele Sprachen, Live Preview und Managed API sind verbindlich
nach [Release 4.4](../v4.4/README.md) verschoben.

## Prioritäten

- `MUST` – Kernlieferung oder zwingende Vorbedingung
- `SHOULD` – hoher Nutzwert, nach Kernarchitektur wahrscheinlich sinnvoll
- `COULD` – sinnvoller Ausbau, aber ohne Blockade des Releases
- `PROPOSED` – Markt-/Architekturvorschlag ohne bestehende Freigabe
- `OUT` – bewusst außerhalb 4.3

## Dokumente

| Dokument | Zweck |
|---|---|
| [CATALOG.md](CATALOG.md) | vollständiger, priorisierter 4.3-Katalog |
| [ROADMAP.md](ROADMAP.md) | Abhängigkeiten, Lieferwellen und Gates |
| [CMS-COMPARISON.md](CMS-COMPARISON.md) | belastbarer Vergleich mit offiziellen CMS-Quellen |
| [DECISIONS.md](DECISIONS.md) | vor Implementierung zu treffende Entscheidungen |
| [SOURCES.md](SOURCES.md) | lokale Evidenz und externe Quellen |

## Nicht duplizieren

Bereits ausgeliefert und deshalb nicht als 4.3-Neuentwicklung zu führen:

- Entwurf/`pending`/Freigabe-Verlauf, Revisionen, Vorschau-Links;
- Rollen, granulare Capabilities, MFA, Passkeys, SSO, Audit-Log;
- REST-/JSON-Endpunkte, optionale Bearer-Anmeldung, Webhooks;
- Formulare, Newsletter, Mitglieder/Abos, Medienbibliothek und Bildableitungen;
- MultiSite-Grundlage, feste DE/EN-Lokalisierung, Sitemaps, Suche und Feed-Pfade;
- Community P1 und Schema v27.

Erweiterungen dieser Bereiche werden für 4.4 geplant. 4.3 enthält dafür keine
vorbereitende Schema- oder Routenänderung, sofern sie nicht für CLI/Alerting nötig ist.

## Schnittstelle zur 4.2.x-Linie

Reproduzierbare 4.2.0-Fehler und P1-Abnahmefehler bleiben in
[`../v4.2.X/`](../v4.2.X/README.md). Sie werden nicht als 4.3-Feature umetikettiert.
