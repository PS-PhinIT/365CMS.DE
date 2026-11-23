# 365CMS 4.4: Inhalte, Vorschau und Headless-Verträge

> **Stand:** 2026-11-23 | **Ausgangsbasis nach 4.3:** CLI + Alerting produktiv | **Ziel:** 4.4.0

4.4 bündelt vier architektonisch zusammenhängende Initiativen. Sie nutzen die
vorhandenen PHP-8.4-Dienste, Provider-Registries und Editor-/API-Verträge, ohne ein
neues Framework oder eine zweite Sicherheitsarchitektur einzuführen:

1. erweiterbare Felder und eigene Inhaltstypen;
2. normalisierte Inhalte in beliebig vielen Sprachen;
3. Live-Splitscreen-Vorschau im Editor;
4. maschinenlesbare, read-first Public-REST-API mit verwaltbaren Integrationsschlüsseln,
   OAuth2-Client-Credentials und OpenAPI.

## Leitplanken

- CSP-Nonce, Trusted Types, Purifier und vorhandene HTML-/JSON-Sanitizer bleiben verbindlich.
- Keine Definition aus Datenbank oder Admin-UI darf PHP-Code, Event-Handler, Shell-
  Aufrufe oder beliebige Renderer laden.
- `ContentTypeRegistry` bleibt die eine Registrierungsstelle für Trash, Suche und
  Exchange; Feld- und Sprachdaten werden nicht außerhalb dieser Verträge versteckt.
- CLI/Alerting bleiben Teil von 4.3. 4.4 setzt sie als Betriebsgrundlage voraus.
- 4.2.x-Patches und Community-P2/P3 werden nicht in die 4.4-Architektur umetikettiert.
- MySQL/MariaDB-Kompatibilität hat Vorrang vor herstellerspezifischen JSON-/View-
  Konstrukten.

## Dokumente

| Dokument | Inhalt |
|---|---|
| [CATALOG.md](CATALOG.md) | vier Kandidaten mit Umfang und Ausschlüssen |
| [ARCHITECTURE.md](ARCHITECTURE.md) | Datenmodell, Dienste, Routen und Kompatibilität |
| [ROADMAP.md](ROADMAP.md) | Lieferwellen, Migration und Abhängigkeiten |
| [SECURITY.md](SECURITY.md) | Rechte-, CSP-, Secret-, API- und Datenschutzvertrag |
| [DECISIONS.md](DECISIONS.md) | zu treffende Produkt- und Architekturentscheidungen |
| [SOURCES.md](SOURCES.md) | lokale Evidenz |

## 4.3/4.4-Grenze

Nach 4.3 verfügbar und vorausgesetzt:

- `CMS/cli.php`, Worker-/Preflight-Aufrufe und beobachtbare Exitcodes;
- Alerting, Deduplizierung/Cooldown und `alert.raised`.

In 4.4 neu:

- Admin-definierte Felder und Typen;
- beliebige Übersetzungssprachen;
- Editor-Splitscreen;
- verwaltete Integrationsidentitäten, Client Credentials, scopes und OpenAPI.
