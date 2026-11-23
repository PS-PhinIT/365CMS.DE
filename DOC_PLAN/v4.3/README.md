# 365CMS 4.3: Releasekatalog

> **Stand:** 2026-11-23 | **Ausgangsbasis:** 4.2.0 / Schema v27 | **Zielversion:** 4.3.0

Dieses Verzeichnis bündelt den fachlich vollständigen Kandidatenkatalog für 4.3.0.
Es ist **kein Zuschnitt- oder Lieferzuspruch**: Einträge ohne verbindliche Entscheidung
bleiben `PROPOSED`, bis Nutzen, Architektur, Migration und Tests freigegeben sind.

## Verbindlicher Kern

Aus den bestehenden Plänen sind für 4.3 bereits verbindlich angelegt:

1. zentrale CLI;
2. Echtzeit-Alerting mit sicheren Kanälen und `alert.raised`;
3. Inhaltsplattform B: Feldgruppen, Feldtypen und eigene Inhaltstypen;
4. Inhaltsplattform C: beliebige Sprachen, erst nach B;
5. Community P2/P3 nach separater Priorisierung.

Neue Kandidaten aus dem Vergleich mit anderen CMS werden nur aufgenommen, wenn 365CMS
die jeweilige Fähigkeit nicht bereits hat oder eine klar begründete Erweiterung fehlt.

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

Erweiterungen dieser Bereiche müssen den vorhandenen Vertrag benennen und begründen,
warum 4.3 ein neues Architektur- oder Bedienproblem löst.

## Schnittstelle zur 4.2.x-Linie

Reproduzierbare 4.2.0-Fehler und P1-Abnahmefehler bleiben in
[`../v4.2.X/`](../v4.2.X/README.md). Sie werden nicht als 4.3-Feature umetikettiert.
