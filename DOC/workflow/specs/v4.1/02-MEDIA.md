# 365CMS Projektdokumentation | Spec 4.1.0: Bereich 2: Medien & Performance
> **Stand:** 2026-11-15 | **Ziel-Version:** 4.1.0 · Schema v26 | **Status:** ✅ umgesetzt in 4.1.0 ([PROGRESS.md](PROGRESS.md)). 2a, 2b | **Übersicht:** [README.md](README.md)

## English (summary)

(2a) AVIF derivatives next to WebP on upload and as a batch job in `/admin/media-tools`, wiring up the existing but
unused `ImageService::convertToAvif()`, with capability detection, size guard (keep AVIF only if smaller), and
delivery through `<picture>` sources. (2b) A persistent media usage index covering posts, pages, hub sites,
downloads, forms, reusable blocks and (from 4.2) community posts, shown in the media library with an "unused media"
filter and checked before deletion.

## Deutsch

### 2a. AVIF-Derivate

**Bestand:** `ImageService` erkennt `imageavif()` (`avifSupport`) und hat `convertToAvif(source, quality, speed)`, das
nirgends aufgerufen wird. WebP-Varianten erzeugt der Upload-Weg bereits.

**Ziel und Technik:**

| Punkt | Festlegung |
|---|---|
| Schalter | *Medien → Einstellungen*: „AVIF zusätzlich erzeugen“ (Standard aus), Qualität (Standard 50), Geschwindigkeit (Standard 6) |
| Erkennung | `function_exists('imageavif')` **und** Probe-Kodierung eines 8×8-Bildes beim Speichern der Einstellung (manche GD-Builds melden die Funktion, scheitern aber); Ergebnis in `media_capabilities` (Option) |
| Upload | nach WebP: `convertToAvif()` je Bildgröße; **nur behalten, wenn kleiner** als WebP der gleichen Größe; Speicher- und Zeitgrenze (Pixel-Budget wie bei WebP, `set_time_limit` nicht verändern) |
| Nachgenerierung | `/admin/media-tools` Aktion „AVIF nachgenerieren“ über `BatchJobService` (Schritte à 20 Bilder, Fortschritt, Abbruch) |
| Auslieferung | `MediaDeliveryService` / Bild-Ausgabe: `<picture><source type="image/avif" …><source type="image/webp" …><img …></picture>`; Theme-Funktionen erhalten die Quellen über einen Helper; ohne AVIF-Datei keine AVIF-Quelle |
| Aufräumen | Löschen eines Mediums löscht alle Derivate (Prüfung vorhandener Lösch-Logik um `.avif` ergänzen) |
| Hook | `cms_media_derivative_created` (Pfad, Format, Bytes) |

**Referenzen:** `intervention/image` wird nicht eingeführt (GD reicht, keine neue Abhängigkeit); CLI-basierte Konverter
(`rosell-dk/avif-convert`, `maximal/php-avif-thumbnailer`) scheiden aus (Shell-Aufrufe), siehe [REFERENCES.md](REFERENCES.md).

**Tests (`TESTS/media-avif/run.php`):** Erkennung mit/ohne Unterstützung (injizierbar), „nur wenn kleiner“, Pixel-Budget,
`<picture>`-Markup mit und ohne AVIF, Löschen entfernt Derivate, Batch-Schritte idempotent.

**Umsetzung (14.11.2026). Präzisierungen:**

| Punkt | Umsetzung |
|---|---|
| Pixel-Budget | Für WebP gab es keins; AVIF bekommt ein eigenes von 25 Megapixeln (`AvifDerivativeService::MAX_PIXELS`). |
| „je Bildgröße“ / Vergleich | WebP entsteht nur für das Original; je Größe wird daher mit der WebP-Fassung derselben Größe verglichen, sonst mit dem Ausgangsbild. Erst in eine temporäre Datei, dann umbenannt. |
| Löschen | Die bisherige Lösch-Logik entfernte keine Derivate (auch kein WebP). `MediaService::deleteItem()` entfernt jetzt Größen, WebP und AVIF; eigenständig hochgeladene Dateien gleichen Namens (eigene Metadaten) und ältere Dateien bleiben. |
| Auslieferung | `pictureSources()`/`pictureHtml()` statt Änderung von `MediaDeliveryService` (Bilder unter `UPLOAD_URL` liefert der Webserver direkt aus). Neben AVIF bekommen Editor.js-Bilder damit erstmals auch die vorhandene WebP-Quelle. |
| Theme | `meridian_get_picture_sources()` liefert zusätzlich `avif_url` (nur vorhandene Dateien, keine Erzeugung zur Laufzeit). |
| Erkennung | Ohne gespeicherte Probe (frische Installation) gilt AVIF als nicht unterstützt, bis die Medien-Einstellungen einmal gespeichert werden. |

### 2b. Verwendungsnachweis für Medien

**Bestand:** `MediaUsageService::buildUsageMap()` sucht Referenzen zur Laufzeit in Beiträgen und Seiten; Downloads haben
einen eigenen Index (`downloads_usage`); Hub-Sites, Formulare und Editor.js-Blöcke werden nicht erfasst.

**Ziel:** dauerhafter Index „Datei X wird verwendet in …“ über alle Inhaltsarten, Anzeige in der Mediathek (Spalte
„Verwendet in“, Detailseite mit Liste), Filter „Ungenutzte Medien“, Warnung vor dem Löschen (Papierkorb-Provider
`deletionWarnings()` nutzt den Index).

**Technik:**
- `CMS\Services\Media\MediaUsageIndex` (neu): `reindexContent(type, id)` nach jedem Speichern (Inhalts-Hooks), `reindexAll()` als Batch-Job, `usagesFor(mediaPath)`, `unusedMedia(offset, limit)`.
- Extraktion je Inhaltsart über eine kleine Schnittstelle `MediaReferenceExtractor` (Registrierung wie die Inhaltstyp-Provider): Beiträge/Seiten (Editor.js-JSON: Bild-, Galerie-, Medien+Text-, Anhang-Blöcke; HTML-Inhalte: `src`/`href`/`srcset`), Beitragsbilder, Hub-Sites (`settings_json`, Einträge), Downloads (Datei-Versionen), Formulare (Hinweistexte mit Bildern), wiederverwendbare Blöcke (Bereich 3), Theme-Customizer-Bilder (Logo, Hintergründe) als Quelle „Design“.
- Pfad-Normalisierung: absolute URL der eigenen Domain, relative URL, Derivat-Namen (`-300x200`, `.webp`, `.avif`) → Originalpfad.

**Datenmodell (v26):**

```sql
CREATE TABLE {p}media_usage (
  media_path VARCHAR(500) NOT NULL,         -- relativ zu uploads/, Original
  content_type VARCHAR(30) NOT NULL,        -- post | page | hub_site | download | form | reusable_block | theme | community_post
  content_id BIGINT UNSIGNED NOT NULL,
  context VARCHAR(30) NOT NULL DEFAULT 'content',  -- content | featured | gallery | attachment | setting
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (media_path(191), content_type, content_id, context),
  KEY idx_content (content_type, content_id)
);
```

**Admin:** Mediathek-Filter „Ungenutzt“, Spalte/Badge, Detail-Liste mit Links in die Editoren; `/admin/media-tools`
„Verwendungsindex neu aufbauen“ (Capability `media.tools`).

**Tests (`TESTS/media-usage/run.php`):** Extraktion je Inhaltsart (inkl. Derivat-Namen und absoluter URLs), Reindex nach
Speichern/Löschen, „Ungenutzt“ korrekt, Löschwarnung, keine Treffer für fremde Domains.

**Umsetzung 2b (14.11.2026). Präzisierungen:**

| Punkt | Umsetzung |
|---|---|
| Einbindung | `MediaUsageService::buildUsageMap()` delegiert an den Index, sobald er einmal vollständig aufgebaut ist (Merker `media_usage_index_built_at`). Mediathek (Spalte, Filter „ungenutzt“) und `MediaTrashProvider::deletionWarnings()` brauchen dadurch keine eigene Änderung. Bis dahin bleibt die Laufzeitsuche (nur Beiträge/Seiten) aktiv, die Mediathek zeigt beim Filtern einen Hinweis. |
| Extraktor-Schnittstelle | `ids(afterId, limit)` und `load(id)`; der Index sucht die Pfade selbst (`MediaPathNormalizer`) und trennt Editor.js-Blöcke: `imageGallery`/`carousel` → `gallery`, `attaches` → `attachment`. Zusätzlicher Kontext `content_en` für die englische Fassung (Badges der Mediathek). |
| Derivate | nur auf das Original zurückgeführt, wenn das Original existiert; sonst ist die Datei selbst ein Original (`team-small.jpg`, `logo.webp`). Nackte Pfade ohne `uploads/` zählen anders als in der alten Laufzeitsuche nicht mehr (zu viele Fehltreffer). |
| Hooks | Neu: `cms_after_form_save`, `cms_form_deleted`, `cms_theme_customizer_saved` (Theme: einmal je Anfrage neu indizieren). Hub-Sites lösen beim endgültigen Löschen keinen Hook aus; ihre Zeilen werden nicht mehr angezeigt (Inhalt fehlt) und beim nächsten Neuaufbau entfernt. Papierkorb zählt weiter als Verwendung. |
| Downloads | Dateiversionen liegen im privaten Speicher, nicht in der Mediathek; erfasst werden Titelbild und Beschreibungen. |
| Wiederverwendbare Blöcke, Community | folgen mit Bereich 3 bzw. 4.2 als eigene Extraktoren. |
| Capability | Die Medien-Werkzeuge verlangen weiter `manage_media`; `media.tools` folgt mit dem Update-Schritt `capabilities_v26`. |
| `unusedMedia()` | arbeitet auf einer übergebenen Pfadliste (die Mediathek listet Dateien aus dem Dateisystem). |

