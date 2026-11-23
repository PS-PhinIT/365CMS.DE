# 365CMS 4.4: Kandidatenkatalog

> **Stand:** 2026-11-23 | **Ziel:** 4.4.0 | **Status:** Planung

## V44-01 – Custom Fields und eigene Inhaltstypen

**Priorität:** MUST · **Marktlücke:** Craft, Drupal und WordPress/ACF ·
**Lokale Basis:** `post_meta_json`, `page_meta_json`, Editor.js Block Registry,
`ContentTypeRegistry`.

### Mindestumfang

1. `CustomFieldGroup` und `CustomFieldDefinition` für Datentyp, Label, Regeln,
   Pflichtstatus, Sichtbarkeit und Ausgabeverhalten.
2. Felder an bestehende Typen binden: Beiträge, Seiten, Downloads,
   Knowledgebase-Einträge und später Hub-Sites.
3. Versioniertes `custom_fields_json` an betroffenen Bestandszeilen; Format wird
   ausschließlich über einen `CustomFieldValueBag` gelesen/geschrieben.
4. Eigene Typen mit Definition, Editor, Route, Archiv/Einzelansicht,
   Berechtigungen, Workflow, Revision, Trash, Suche und Content Exchange.
5. Feldwerte in Revisionen und Exchange-Paketen; nur `searchable`/`indexable`
   Felder fließen in den Suchindex.
6. Theme-Helfer liefert typisierte, escapte Werte; Templates erhalten keine
   unvalidierten PHP-/HTML-Definitionen.

### Datenoberfläche

- Definitionen: `custom_field_groups`, `custom_fields`, `content_types`;
- Werte bestehender Typen: je Typ ein validiertes JSON-Feld, z. B.
  `posts.custom_fields_json`;
- Einträge eigener Typen: `content_entries` mit normalisierten Pflichtspalten und
  validiertem `fields_json`.

Das ist keine zweite Tabelle je Inhaltstyp und kein Schema-Baukasten, der
beliebige SQL-Definitionen zulässt.

### Erfolgskriterien

- Ein Feld kann ohne Core-Release angelegt, sortiert, deaktiviert und exportiert
  werden.
- Ein eigener Typ kann über Admin, API, Theme, Suche, Trash, Revision und ZIP-
  Import/Export vollständig verarbeitet werden.
- Unbekannte Typen/Versionen führen zu protokolliertem Fallback, nicht zu PHP-Code
  oder kaputten Listen.
- Ein bestehender Beitrag bleibt ohne Migrationsfehler und mit leerem Werte-Bag
  lesbar.

## V44-02 – Normalisiertes N-Sprachen-Modell

**Priorität:** MUST · **Lokale Basis:** `title_en`/`content_en`,
`ContentLanguageCopyService`, `TranslationStatusService`,
`EditorJsTranslationPipeline`.

### Zielmodell

`content_translations` enthält je logisch identischem Inhalt und Locale:

| Spalte | Zweck |
|---|---|
| `entity_type` | Registry-Schlüssel, z. B. `post`, `page`, `download` |
| `entity_id` | ID der Inhaltseinheit |
| `locale` | BCP-47-artige, auf kanonische CMS-Locale begrenzte Kennung |
| `slug` | Sprach-Slug, uniqueness je Typ/Site/Locale |
| `title`, `content_json`, `excerpt` | sprachlicher Inhalt |
| `status` und Zeitfelder | Sprachstatus, Veröffentlichung, Freigabe und Ablauf |
| `meta_json` | nur durch registrierte SEO-/Feldadapter interpretierte Werte |
| `source_locale`, `source_version` | Übersetzungsherkunft und Veraltet-Erkennung |

### Migration

1. Schema als dual-read/dual-write vorbereiten.
2. DE-Primärwerte und vorhandene `_en`-Werte idempotent in
   `content_translations` kopieren.
3. Resolver liefern zunächst Altspalten als Fallback; neue Locale lesen aus der
   Übersetzungstabelle.
4. Dienste für Routing, Suche, Sitemap, API, Exchange, Revisions,
   Medienreferenzen, SEO und Translation Status auf den Resolver umstellen.
5. Erst nach einer vollständigen Release-Wartezeit werden `_en`-Schreibpfade
   entfernt. 4.4 löscht die Altspalten nicht pauschal.

### Erfolgskriterien

- mindestens DE, EN und zwei weitere Locale im selben Release testbar;
- DE/EN-URLs, APIs und Exporte bleiben rückwärtskompatibel;
- Fallback ist je Typ explizit, deterministisch und im UI sichtbar;
- KI-Pipeline überschreibt keine unveränderte Sprachfassung und erzeugt den
  richtigen `source_version`-Status;
- Slug-Kollisionen, unvollständige Übersetzungen und `hreflang` sind testbar.

## V44-03 – Live-Splitscreen-Vorschau

**Priorität:** SHOULD · **Lokale Basis:** `/admin/post-editor-api`,
`EditorJsRenderer`, `ContentNormalizer`, `HtmlSanitizer`, `PurifierService`,
`/preview/<token>`.

### Vertrag

- neuer, authentifizierter POST-Handler, z. B.
  `action=live_preview`, im bestehenden `post-editor-api.php`;
- nur aktuelle Editor.js-JSON-Struktur und erlaubte Metafelder;
- 300–600 ms Debounce im Browser, Abbruch älterer Requests;
- Körper-, Block-, Medien- und Zeitlimits wie beim normalen Speichern;
- Rendering nur im Preview-Modus; keine Speicherung, Hooks für "saved" oder
  externe Seiteneffekte;
- Ausgabe in einer isolierten Vorschauansicht, nicht als beliebiges HTML-Fragment
  in den Editor einfügen.

### Iframe/CSP

Empfohlener Frame-Vertrag:

```html
<iframe sandbox="allow-same-origin"
        referrerpolicy="no-referrer"
        title="Live-Vorschau"></iframe>
```

Nicht erlaubt: `allow-scripts`, `allow-forms`, `allow-popups`,
`allow-top-navigation`, Inline-Eventhandler oder ein von Nutzerinhalt bestimmtes
`srcdoc`. Preview-JS/CSS wird nur aus versionierten Core-/Theme-Assets geladen.
Trusted Types werden zum Schreiben des DOM-Fragments genutzt.

### Erfolgskriterien

- Editoränderung erscheint neben dem Bearbeitungsfenster ohne Vollseitenwechsel;
- Vorschau weicht vom gespeicherten Renderer nicht ab;
- fehlgeschlagene Teilvorschau erhält eine textliche Fehlermeldung und zerstört
  den Editorzustand nicht;
- keine POST-Nebenwirkung außerhalb der Preview, keine dauerhafte Revision,
  kein externer Request aus Nutzerinhalt;
- Tastatur, Fokus, hoher Zoom, schmaler Bildschirm und fehlender JS-Pfad sind
  definiert.

## V44-04 – Managed Public API

**Priorität:** MUST · **Lokale Basis:** `ApiRouter`, `JwtService`, Bearer-Auth,
Capability-Prüfung, Rate-Limits.

### Lieferumfang

1. Admin-Verwaltung für maschinenlesbare Integrationen: Name, Beschreibung,
   Scope, Ablaufdatum, Status, letzter Zugriff.
2. API-Schlüssel werden nur einmal angezeigt und danach mit Salt und einem starken
   Passwort-Hash/KDF
   gespeichert; ein nichtgeheimer Präfixteil dient dem Lookup.
3. Client-Credentials-Endpunkt tauscht `client_id`/`client_secret` gegen einen
   kurzlebigen JWT; direkter API-Key wird optional nur für einfache,
   read-only Integrationen angeboten.
4. Scopes folgen vorhandenen Capabilities, ohne Admin-Login zu simulieren.
   Beispiele: `content:read`, `media:read`, `search:read`, `posts:write`,
   `api:token:rotate`.
5. Read-Only-REST für Inhalte, Taxonomien, Medienmetadaten, Suche, Locale,
   Sitemapdaten und Status ist der 4.4-MVP.
6. Schreibscopes können administrativ vergeben werden, werden aber nur auf
   ausdrücklich freigegebenen Endpunkten mit Idempotency-Key wirksam.
7. `/api/v1/openapi.json` wird serverseitig aus derselben Route-/Schema-Registry
   erzeugt; kein unversionierter Swagger-Upload.
8. GraphQL bleibt ein optionaler Anschluss hinter `ContentTypeRegistry`;
   ohne belastbaren Bedarf ist es ausdrücklich **nicht** Teil des 4.4-MVP.

### Erfolgskriterien

- ein Schlüssel sieht nur erlaubte Inhalte und Locale;
- Ablauf, Widerruf, Rotation, Rate-Limit und IP-/Client-Missbrauch sind testbar;
- kein Secret erscheint im Audit, Fehlertext, Export, Query-String oder Trace;
- bestehende Browser-Session → JWT-Nutzung funktioniert unverändert;
- OpenAPI beschreibt Auth, Scopes, Fehler, Pagination und Rate-Limit-Header.

## 4.4-Nichtziele

- GraphQL als Parallel-API, solange REST/OpenAPI den Use Case erfüllt;
- benutzerdefinierte PHP-Typen oder SQL-Felder aus der Admin-Oberfläche;
- ein visuelles Page-Builder-Framework;
- neue Zahlungs-, Mitglieder- oder Community-Domänen;
- Umzug der Community- oder Download-Inhalte in die neue Übersetzungstabelle ohne
  eigene Provider-Migration und Abnahme.
