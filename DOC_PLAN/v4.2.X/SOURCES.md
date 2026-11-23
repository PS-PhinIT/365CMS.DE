# 365CMS 4.2.x: Quellen und Abgrenzung

## Lokale Quellen

| Quelle | Verwendung |
|---|---|
| [v4.2/ROADMAP.md](../v4.2/ROADMAP.md) | offene P1-Abnahme und P2/P3-Grenze |
| [v4.2/TEST-PLAN.md](../v4.2/TEST-PLAN.md) | Community-Testverträge |
| [v4.2/SECURITY-PRIVACY.md](../v4.2/SECURITY-PRIVACY.md) | Rechte-, Datenschutz- und Spamgrenzen |
| [STATUS.md](../STATUS.md) | verbindlicher offener Gesamtstand |
| [../../DOC/core/STATUS.md](../../DOC/core/STATUS.md) | signierte Updates als offener Kernpunkt |
| [../../DOC/core/DATABASE-SCHEMA.md](../../DOC/core/DATABASE-SCHEMA.md) | Schema v27, Integritätskonstanten und Zählwerte |
| [../../DOC/core/HOOKS-REFERENCE.md](../../DOC/core/HOOKS-REFERENCE.md) | 4.2-Hook-Verträge |
| [../../Changelog.md](../../Changelog.md) | ausgelieferte 4.2.0-Funktionen |

## Externer Vergleich

Offizielle CMS-Quellen dienen **nicht** als Patch-Nachweis. Sie können nur begründen,
warum ein fehlender 4.2.0-Vertrag sicherheits- oder betriebsrelevant ist. Der
Vergleichskatalog für echte Folgefunktionen liegt unter
[../v4.3/CMS-COMPARISON.md](../v4.3/CMS-COMPARISON.md).

## Zuordnung bei Konflikt

1. Code ist Wahrheit für den 4.2.0-Ist-Stand.
2. Changelog und Doku erklären, was 4.2.0 öffentlich verspricht.
3. `v4.2/` beschreibt den historischen Lieferumfang und Restbestand.
4. Ein Abgleich zwischen 2 und 3 wird in `42X-07` oder als klarer Regressionstest
   entschieden.
5. Neue Betriebsfähigkeiten ohne 4.2-Verletzung gehen nach 4.3; Custom Fields,
   N-Sprachen, Live Preview und Managed API nach 4.4.
