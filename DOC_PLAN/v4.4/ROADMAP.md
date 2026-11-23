# 365CMS 4.4: Lieferplan

> **Stand:** 2026-11-23 | **Voraussetzung:** 4.3.0 mit CLI und Alerting

## W0 – Architekturfreigabe

- `DECISIONS.md` vollständig entscheiden.
- Schema-Pilot in temporärer Installation testen.
- Security- und CSP-Threat-Model abnehmen.
- `TESTS/run-all.php`-Discovery für neue Suiten vorbereiten.

**Gate:** keine Admin-Maske vor freigegebenem Definitions-, Locale-, Scope- und
Preview-Vertrag.

## W1 – Feld- und Typ-Grundlage

1. Felddefinitionen, Gruppen, Validierung und Versionierung.
2. `custom_fields_json` für Beiträge und Seiten.
3. Admin-Editor für Gruppe/Feld.
4. Revision, Suche und Exchange.

**Gate:** ein Page-/Post-Feld bleibt bei Speichern, Revision, Export, Import,
Suche, Trash und Restore erhalten.

## W2 – Content Types und Integrationsprovider

1. Definition eigener Typen.
2. generische Admin-Liste/Editor.
3. Routing, Theme-Fallback, Archiv/Einzelansicht.
4. ContentTypeProvider für Trash, Suche, Exchange und Sitemap.

**Gate:** ein Testtyp läuft durch denselben Lebenszyklus wie ein Core-Typ, ohne
Sonderfälle in `TrashService`, `ContentExchangeService` oder `SearchIndexService`.

## W3 – Übersetzungsmodell

1. Locale-Registry und `TranslationRepository`.
2. Dual-read/dual-write für DE/EN.
3. Migration vorhandener Werte.
4. Routing, Suche, Sitemap, API und SEO.
5. AI- und Translation-Status-Adapter.

**Gate:** DE/EN-Ausgabe bleibt byte-/URL-kompatibel; mindestens FR und IT werden
Ende-zu-Ende geprüft.

## W4 – Live Preview

1. Preview-DTO und Größenlimits.
2. serverseitiger Rendering-Endpunkt.
3. iframe-/CSP-Vertrag.
4. Split-View, Debounce, Abort, Fokus/Reflow und Fehlerfall.
5. Parität mit Vollvorschau und gespeichertem Theme.

**Gate:** identische Core-Blöcke rendern in Voll- und Live-Preview gleich; keine
Persistenz- oder externe Nebenwirkung.

## W5 – Managed API

1. Client-/Secret-Registry und Admin.
2. Scope-Resolver und Client Credentials.
3. read-first Public-Endpoints.
4. OpenAPI-Generation.
5. optionale, begrenzte Schreibendpunkte mit Idempotency.

**Gate:** Scope-Matrix, Token-Revocation, Rate-Limits, Secret-Handling, Locale-
Fallback und öffentliche Inhalte werden ohne Browser-Session geprüft.

## W6 – Downloads, Knowledgebase und Abschluss

1. Custom Fields/Translations auf Downloads und KB erweitern.
2. Migration weiterer Bereiche nur nach Bedarfsliste.
3. v4.4.0-Paket, Changelog, Doku, Performance- und Sicherheitsabnahme.

## Empfohlene Releasegrenze

**MVP 4.4.0:**

- Custom Fields auf Posts/Pages;
- eigene Inhaltstypen;
- N-Sprachen für Posts/Pages und Sitemap/API;
- Live Preview;
- read-only Managed API + OpenAPI;
- Integrationsschlüssel/Client Credentials.

**4.4.x/4.5 bei Bedarf:**

- Custom Fields/Translations auf jedem weiteren Domain-Typ;
- GraphQL;
- Schreibzugriff für dynamisch definierte Typen;
- komplexe Automationen oder relationale Feldtypen.
