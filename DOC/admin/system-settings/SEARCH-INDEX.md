# 365CMS – Admin: Suchindex

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

*System → Tools → Search index* (`/admin/search-index`, capability `manage_search_index`) shows the index status per
content language, rebuilds the index in batches (the frontend keeps searching the old index until the rebuild is
finished) and evaluates anonymous search terms (top terms, terms without results). Hub sites are not indexed.

## Deutsch

### Reiter

| Reiter | Inhalt |
|---|---|
| Status | Je Sprache und Inhaltstyp: Dokumente im Index, letzter Aufbau, Hinweis „Neuaufbau empfohlen“. *Neu aufbauen* startet den Neuaufbau mit Fortschritt und Abbrechen |
| Suchbegriffe | Häufigste Begriffe und Begriffe ohne Treffer je Zeitraum und Sprache |
| Einstellungen | Protokollierung der Suchbegriffe an/aus, Aufbewahrung der Tageswerte (7–3650 Tage, Standard 365) |

### Neuaufbau

- Läuft als `BatchJobService`-Auftrag (`search_index_rebuild`) über `/admin/search-index-api`, je Schritt höchstens
  50 Dokumente, in eine Aufbau-Datei. Erst nach dem letzten Stapel ersetzt sie den aktiven Index.
- Änderungen während des Neuaufbaus gehen nicht verloren; Sammelaktionen und erreichte Veröffentlichungszeitpunkte
  gleicht ein stündlicher Abgleich nach.
- Indexiert wird, was die Such-Provider liefern: veröffentlichte Seiten und Beiträge je Sprache, nicht im Papierkorb
  ([CONTENT-TYPE-PROVIDERS.md](../../core/CONTENT-TYPE-PROVIDERS.md)).

### Suchbegriff-Statistik

Gespeichert werden nur normalisierter Begriff, Sprache, Trefferzahl und Zeitpunkt – keine IP, keine Benutzer-ID,
keine Session, kein User-Agent. Begriffe, die wie E-Mail-Adressen oder lange Ziffernfolgen aussehen, sowie Anfragen von
Bots und Prefetches werden nicht gespeichert. Die Rohdaten (`search_queries`) werden täglich je Tag, Sprache und
Begriff in `search_term_daily` verdichtet und gelöscht; die Aufbewahrung der Tageswerte steht auch unter
*Recht → Löschfristen* (Regel `search_terms`).

### Dateien

`CMS/admin/search-index.php`, `CMS/admin/search-index-api.php`, `CMS/admin/views/system/search-index*.php`,
`CMS/core/Services/Search/SearchIndexService.php`, `CMS/core/Services/Search/SearchTermStatsService.php`
