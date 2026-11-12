# 365CMS – Projektdokumentation | Abschnitt: Core – Inhaltstyp-Provider

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Trash, content export/import and the search index never contain special cases for individual content types. Every type (pages, posts, media, hub sites, taxonomies, downloads, form submissions …) registers a provider with `CMS\Services\ContentTypes\ContentTypeRegistry` via the action `cms_register_content_type_providers`. A provider implements `ContentTypeProvider` (`type()`, `label()`, `capability()`) plus one or more capability interfaces: `TrashProvider`, `ExchangeProvider`, `SearchDocumentProvider`. The registry fires the action once on first access; for each capability and type the first registration wins. Plugins use the same API.

## Deutsch

### Zweck

Papierkorb, Inhalts-Export/-Import und Suchindex kennen nur Schnittstellen. Welche Inhaltstypen sie bedienen, bestimmen die angemeldeten Provider. Damit gibt es in diesen Funktionen keine Abfragen wie „falls Hub-Site, dann …“ ([KLARSTELLUNGEN.md §5](../workflow/specs/KLARSTELLUNGEN.md)). Plugins können eigene Inhaltstypen auf dieselbe Weise anschließen.

| Fähigkeit | Schnittstelle | Genutzt von |
|---|---|---|
| Papierkorb | `TrashProvider` | Papierkorb-Seite, Bulk-Aktionen, automatisches Leeren per Cron |
| Export/Import | `ExchangeProvider` | Export als ZIP mit `manifest.json`, Import mit Vorschau und Konfliktstrategie |
| Suchindex | `SearchDocumentProvider` | Neuaufbau je Sprache in Stapeln, inkrementelle Aktualisierung |

### Anmeldung

```php
use CMS\Hooks;
use CMS\Services\ContentTypes\ContentTypeRegistry;

Hooks::addAction(ContentTypeRegistry::REGISTER_ACTION, static function (ContentTypeRegistry $registry): void {
    $registry->register(new MeinTrashProvider());
});
```

- Die Action `cms_register_content_type_providers` wird beim ersten Zugriff auf die Registry einmal ausgelöst. Core-Bereiche melden ihre Provider in ihrer `FeatureHooks\<Bereich>Hooks`-Klasse an, Plugins beim Laden.
- Ein Provider wird unter jeder Fähigkeits-Schnittstelle abgelegt, die er implementiert. Getrennte Klassen je Fähigkeit für denselben Typ sind ebenso möglich.
- Typ-Schlüssel: Muster `[a-z][a-z0-9_]{0,39}`, stabil (z. B. `page`, `post`, `media`, `hub_site`, `category`, `download`, `form_submission`). Ungültige Typen werden protokolliert und ignoriert.
- Je Fähigkeit und Typ gilt die erste Anmeldung; spätere Doppelanmeldungen werden protokolliert (Kanal `core.content-types`) und ignoriert. `register()` liefert `false`, wenn nichts neu angemeldet wurde.
- Abfragen: `trashProviders()`, `exchangeProviders()` (sortiert nach `importPriority()`), `searchProviders()` sowie `trashProvider($type)`, `exchangeProvider($type)`, `searchProvider($type)`.

### Gemeinsame Basis `ContentTypeProvider`

| Methode | Bedeutung |
|---|---|
| `type()` | Typ-Schlüssel |
| `label()` | übersetzte Bezeichnung im Plural („Seiten“) |
| `capability()` | Recht für Einträge dieses Typs; die Funktionen prüfen es zusätzlich zu ihrem eigenen (z. B. `manage_trash` und `manage_pages`) |

### Papierkorb (`TrashProvider`)

| Methode | Vertrag |
|---|---|
| `countTrashed($search)`, `listTrashed($offset, $limit, $search)` | Einträge im Papierkorb als `TrashItem` (Typ, ID, Titel, `trashedAt`, `trashedBy`, vorheriger Status, Bearbeiten-Link, Zusatzangaben), zuletzt gelöschte zuerst |
| `restore($id, $userId)` | stellt den vorherigen Status wieder her und löst Konflikte auf (Slug, Dateiname); der neue Wert steht in `TrashResult::$data['slug']` |
| `deletionWarnings($id)` | Hinweise vor dem endgültigen Löschen, z. B. Verwendungen eines Mediums; leer = unbedenklich |
| `deletePermanently($id, $userId, $confirmed)` | löscht samt abhängiger Daten (Revisionen, Verwendungen, Dateien). Gibt es Warnungen und ist `$confirmed` nicht gesetzt, liefert der Provider `TrashResult::CODE_CONFIRMATION_REQUIRED` mit den Hinweisen in `$data['warnings']` |
| `expiredIds($trashedBefore, $limit)` | Einträge, deren Frist abgelaufen ist. Das automatische Leeren löscht ohne Bestätigung; Einträge mit Warnungen bleiben deshalb liegen |

- In den Papierkorb verschiebt der Inhaltstyp selbst (Liste, Editor, Bulk-Aktion). Dabei merkt er sich vorherigen Status, Zeitpunkt und Benutzer (`previous_status`, `trashed_at`, `trashed_by`); der Eintrag verschwindet aus Frontend, Sitemap, Feeds und Suche. Für Beiträge, Seiten und Hub-Sites gibt es diese Spalten seit Schema v25 ([DATABASE-SCHEMA.md](DATABASE-SCHEMA.md#schema-v25-400)); Hub-Sites haben keinen Status und gelten mit gesetztem `trashed_at` als gelöscht.
- IDs sind die IDs des Typs. Typen ohne eigene numerische ID (Medien sind dateibasiert) führen eine eigene Papierkorb-Tabelle und verwenden deren ID.
- Ergebnisse: `TrashResult::success()`, `failure($code, $message, $data)` mit `CODE_NOT_FOUND`, `CODE_INVALID_STATE`, `CODE_ERROR`, sowie `confirmationRequired($warnings)`. Meldungen sind für die Oberfläche gedacht und enthalten keine Ausnahmetexte.
- Audit-Log, CSRF und Rechteprüfung übernimmt der Aufrufer (Papierkorb-Seite, Cron).

### Export/Import (`ExchangeProvider`)

| Methode | Vertrag |
|---|---|
| `importPriority()` | Importreihenfolge, kleiner zuerst: Kategorien 10, Schlagwörter 11, Medien 20, Formulare 40, Seiten 50, Beiträge 51, Hub-Sites 60 |
| `countExport($filter)`, `exportBatch($filter, $cursor, $limit)` | Export in Stapeln über einen providerspezifischen Cursor (letzte ID oder Position, 0 = Anfang). `ExportBatch` enthält JSON-serialisierbare Datensätze mit mindestens `id` (bei Medien der relative Pfad), die zugehörigen Dateien (Archivpfad unter `files/` ⇒ absoluter Quellpfad), den nächsten Cursor und `done` |
| `preview($record, $context)` | schreibt nie; liefert `ImportPreview` mit `ACTION_CREATE`, `ACTION_CONFLICT` oder `ACTION_INVALID`, Bezeichnung, Konfliktschlüssel und Hinweis |
| `import($record, $context)` | legt an oder löst Konflikte nach `ImportContext::$conflictStrategy` (`skip`, `overwrite`, `rename`) und beachtet `$importAsDraft`; Ergebnis `ImportResult` (`created`, `updated`, `renamed`, `skipped`, `failed`) mit neuer ID |

`ExchangeFilter` beschreibt die Auswahl (Status, Zeitraum, Mediendateien ja/nein, typspezifische Optionen). `ImportContext` trägt Strategie, importierenden Benutzer, Autorenzuordnung (`authorFor()`), das Manifest und die ID-Zuordnungen früherer Typen (`map()`, `resolve()`, `mapOf()` mit Namensräumen wie `category`, `media`, `media_url`). So schreiben spätere Typen Verweise auf Kategorien, Medien und Autoren um. Entpackte Dateien liefert nur `stagedFile()`; die Methode prüft per `realpath`, dass der Pfad im Staging-Verzeichnis liegt. Filter und Kontext lassen sich über `toArray()`/`toState()` im Zustand eines `BatchJobService`-Auftrags speichern und fortsetzen. Editor.js-Inhalte werden beim Import über die bestehenden Services erneut bereinigt.

### Suchindex (`SearchDocumentProvider`)

| Methode | Vertrag |
|---|---|
| `countDocuments($locale)` | Anzahl indexierbarer Dokumente der Sprache |
| `documents($locale, $cursor, $limit)` | `SearchDocument`-Liste aufsteigend nach ID, nur IDs größer als `$cursor` (fortsetzbarer Neuaufbau) |
| `document($id, $locale)` | aktueller Stand für inkrementelle Aktualisierung; `null` = nicht (mehr) indexieren |

Geliefert werden nur öffentlich sichtbare Inhalte: veröffentlicht, nicht im Papierkorb, Sprache vorhanden. `SearchDocument::$content` ist reiner Text ohne HTML oder Editor.js-JSON. Hub-Sites melden keinen Such-Provider an.

### Provider im Core

| Typ | Papierkorb | Export/Import | Suche |
|---|---|---|---|
| Seiten, Beiträge | ✓ | ✓ | ✓ |
| Medien | ✓ | ✓ | – |
| Hub-Sites | ✓ | ✓ | – |
| Kategorien, Tags | – | ✓ | – |
| Downloads | ✓ | – | – |
| Formulare | ✓ | ✓ | – |
| Formulareingänge | ✓ | – (Personendaten) | – |

Die Export/Import-Provider des Cores liegen unter `CMS/core/Services/Exchange/` (Formulare: `CMS/core/Services/Forms/FormContentProvider.php`); die Oberfläche beschreibt [CONTENT-EXCHANGE.md](../admin/system-settings/CONTENT-EXCHANGE.md).

### Tests

`php TESTS/content-types/run.php` prüft die Registry (Anmeldung je Fähigkeit, Doppelanmeldung, ungültige Typen, Sortierung, einmaliges Auslösen der Action) und die Wertobjekte, darunter die `realpath`-Prüfung von `ImportContext::stagedFile()`. `php TESTS/run-all.php` führt alle Suiten aus.

### Verwandte Dokumente

[SERVICES.md](SERVICES.md) · [HOOKS-REFERENCE.md](HOOKS-REFERENCE.md) · [../plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md)
