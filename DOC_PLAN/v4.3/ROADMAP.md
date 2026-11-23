# 365CMS 4.3: Lieferphasen

> **Stand:** 2026-11-23 | **Ziel:** 4.3.0

## Prinzipien

1. Patchen, nicht umetikettieren: 4.2.x bleibt die Heimat von Regressionen.
2. Architekturverträge vor UI: CLI, Inhaltstypen, Sprachen, Integrationsschlüssel und
   Cache-/Speicherverträge werden zuerst festgelegt.
3. C setzt B voraus; Community P2 setzt P1-Abnahme voraus.
4. Jede Oberfläche wird mit ihren Nebenwegen getestet: API, Suche, Sitemap,
   Papierkorb, Exchange, DSGVO, Theme und Benachrichtigung.

## Lieferwellen

### W0 – Entscheidungen und Baseline

- `DECISIONS.md` auf freigegeben setzen.
- 4.2.x-Blocker 42X-01 bis 42X-07 einordnen.
- Schema-/API-Migrationsstrategie und SemVer-Regeln dokumentieren.
- Verantwortliche Testsuiten und Pflichtumgebungen festlegen.

**Gate:** keine Implementierung von B, C oder neuen Integrationen mit offenen
Kernentscheidungen.

### W1 – Betriebsgrundlage

- V43-01 zentrale CLI;
- V43-02 Alerting;
- V43-04 gemeinsamer Preflight;
- erster Teil von V43-21 gemeinsamer Worker-Vertrag.

**Gate:** CLI/Alerts testen fehlende Ressourcen, Abbrüche, ungültige Secrets,
Rollen/Audit und Exitcodes; kein Web-SAPI-Zugriff.

### W2 – Inhaltsplattform B

- V43-06 Feldgruppen/Feldtypen;
- V43-07 eigene Inhaltstypen;
- V43-08 Definitionsvertrag;
- V43-09 Editor/Theme/API;
- V43-10 Migration, Export/Import, Preview.

**Gate:** ein kompletter Testtyp durchläuft Erstellen, Freigabe, Revision, öffentliche
Ausgabe, Suche, Sitemap, Trash, Restore, API, Exchange und DSGVO-Löschung.

### W3 – C-Grundlage und Sprachen

- V43-11 Sprachmodell/Migration;
- V43-12 Sprachverwaltung;
- V43-13 öffentliche Sprachrouten;
- V43-14 Übersetzungsstatus;
- V43-15 inkrementelle Migration weiterer Bereiche.

**Gate:** vorhandene DE/EN-Inhalte bleiben identisch erreichbar; Fallback, Redirect,
Canonical/Hreflang und Cache sind für mindestens drei Sprachen verifiziert.

### W4 – Integrationen und Betriebserweiterungen

- V43-16 Integrationsschlüssel;
- V43-17 OpenAPI;
- V43-18 Webhook-Inspektor;
- V43-20 Cache-Tags;
- V43-23 Medien-Speichertreiber, sofern Entscheidung positiv;
- V43-05 Log-Senken/Health.

**Gate:** kein API-Schlüssel kann Admin-/Theme-Rechte oder Workflow-Regeln umgehen;
Webhook-Replays und Cache-Invalidierung sind deterministisch getestet.

### W5 – Community P2

- V43-26 Verwarnungen;
- V43-27 Zusammenführen/Teilen;
- V43-28 bis V43-30 nach Priorisierung;
- V43-31 nur nach 4.2.X-07.

**Gate:** Moderation, Zähler, Redirects, Benachrichtigungen, Export, Retention,
private Foren und Papierkorb sind je Funktion getestet.

### W6 – optionale Erweiterungen

Nur mit freiem Kapazitäts- und Risikobudget:

- V43-19 Automation;
- V43-22 Query-/Cache-Budget;
- V43-24/V43-25 Medienprofile und Drift;
- V43-32 Community-Nachlauf;
- V43-33 bis V43-35 Community-P3;
- V43-37/V43-38 Redaktions-Erweiterungen.

## Release-Gates

| Gate | Muss erfüllt sein |
|---|---|
| Upgrade | 4.2.0/v27 → Ziel-Schema mit Datenvergleich, Rollback/Restore und Idempotenz |
| Kompatibilität | bestehende Themen, Plugins, Admin-Routen und DE/EN-Pfade bleiben lauffähig |
| Sicherheit | keine Secret-Leaks, Scope-Umgehung, ACL-Lücke, offene Upload-/Exportpfade |
| Performance | definierte Query-/Queue-Budgets für B/C-Listen und Community-P2 |
| Doku/Paket | Katalog, Changelog, Release-Manifest, Checksums, Signaturen falls verfügbar |
| Testdiscovery | `php TESTS/run-all.php`; keine feste Suiten-Summe in der Planung |

## Empfohlener 4.3-Zuschnitt

**Kern:** V43-01, V43-02, V43-06 bis V43-15, V43-26, V43-27.

**Nach Kapazität:** V43-03, V43-04, V43-16 bis V43-21, V43-28 bis V43-30.

**Bewusst nachgelagert:** V43-19, V43-23 bis V43-25, V43-33 bis V43-39.
