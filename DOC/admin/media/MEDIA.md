# 365CMS Projektdokumentation | Admin: Medienverwaltung

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **Route:** `/admin/media` | **Capability:** `manage_media` | **CSRF-Aktion:** `admin_media`

## English (summary)

The media manager at `/admin/media` (`CMS/admin/media.php` → `CMS/admin/modules/media/MediaModule.php`) is a file-system based library under `CMS/uploads/` with metadata (categories, tags, alt texts) in `CMS/config/media-meta.json` and settings in `CMS/config/media-settings.json`.

- Tabs: `library` (default), `featured`, `check`, `categories`, `settings`.
- Actions: `upload`, `replace_item`, `replace_items`, `create_folder`, `rename_item`, `move_item`, `delete_item`, `bulk_items`, `assign_category`, `add_category`, `delete_category`, `save_settings`, `start/process/cancel_media_processing_job`.
- Upload batch: max. 20 files and 100 MB per request, file names max. 180 characters.
- Delivery of protected files goes through `GET /media-file?path=…` (`MediaDeliveryService`).
- `strip_exif` (default on) removes EXIF/GPS, XMP, IPTC and comments from JPEG, PNG and WebP on every upload path. Preserved originals (library, member uploads, replace, Editor.js images) are cleaned without re-encoding (`ImageMetadataStripper`, encoded pixels unchanged, ICC profile and orientation retained); other JPEG/PNG paths may re-encode with GD, while WebP is still cleaned without re-encoding. Every GD output applies JPEG EXIF orientation (`ImageOrientation`, all eight values) and keeps alpha. GD's EXIF reader is JPEG-only.
- Optional AVIF derivatives (`auto_avif`, `AvifDerivativeService`) with `<picture>` output (`display: contents`), deleting an image also removes its generated sizes/WebP/AVIF, and a persistent usage index (`media_usage`, `MediaUsageIndex`, extensible via `cms_register_media_reference_extractors`).

## Deutsch

### Architektur

| Schicht | Datei | Aufgabe |
|---|---|---|
| Einstieg | `CMS/admin/media.php` | Aktions-Allowlist, Upload-Grenzen, Tab-Routing |
| Modul | `CMS/admin/modules/media/MediaModule.php` | Listen, Filter, Dateiaktionen, Einstellungen, Verarbeitungsjobs |
| Views | `CMS/admin/views/media/library.php`, `featured.php`, `check.php`, `categories.php`, `settings.php` | Tabs |
| Services | `CMS/core/Services/MediaService.php` (Fassade), `Media/MediaRepository.php`, `Media/UploadHandler.php`, `Media/ImageProcessor.php` | Dateisystem, Metadaten, Upload-Validierung, Bildverarbeitung |
| Bildmetadaten | `Media/ImageOrientation.php`, `Media/ImageMetadataStripper.php` | EXIF-Ausrichtung für GD-Ausgaben, `strip_exif` ohne Neukodierung |
| Nutzung | `CMS/core/Services/MediaUsageService.php` (Fassade), `Media/MediaUsageIndex.php`, `Media/MediaReferenceExtractor.php`, `Media/MediaUsageRebuildService.php`, `Media/Usage/*` | Wo wird eine Datei verwendet (Verwendungsnachweis über alle Inhaltsarten) |
| AVIF | `Media/AvifDerivativeService.php`, `Media/AvifRegenerationService.php` | AVIF-Fassungen erzeugen, prüfen und nachgenerieren |
| Auslieferung | `CMS/core/Services/MediaDeliveryService.php` → `GET /media-file` | Geschützte Dateien, Member-Uploads |
| Upload-API | `ApiRouter`: `POST /api/upload`, `GET\|POST /api/media` | Editor- und Plugin-Uploads |
| Metadaten | `CMS/config/media-meta.json` | Kategorien, Tags, Alt-Texte. Schreibzugriffe laufen über `MediaRepository::updateMeta()` unter einer Dateisperre (`media-meta.json.lock`), damit sich parallele Uploads, Papierkorb und Import nicht gegenseitig überschreiben. Je Datei: `getFileMeta()`, `setFileMeta()` (ersetzen/entfernen), `mergeFileMeta()` (einzelne Felder). |
| Einstellungen | `CMS/config/media-settings.json` | siehe Tab „Einstellungen“ |

### Tab „Bibliothek“ (`library`)

- **Navigation:** Ordnerbaum über `?path=`; Ansicht `?view=list|grid`.
- **Filter:** Suche `q` (max. 120 Zeichen), `file_type` (`image`, `document`, `video`, `audio`, `archive`, `other`), `extension`, `size_filter` (`tiny` … `huge`), `modified_filter` (`today`, `7d`, `30d`, `year`), `usage_filter` (`used`, `unused`), `category`, `orphan_days` (0/30/90/180/365 Tage ungenutzt).
- **Ordner `member/`:** enthält Uploads von Mitgliedern. Das Öffnen verlangt eine Bestätigung (`confirm_member=1`).
- **Einzelaktionen:** Upload, Ersetzen (gleicher Dateiname, Verweise bleiben gültig), Umbenennen, Verschieben, Löschen, Kategorie zuweisen.
- **Sammelaktionen (`bulk_items`):** `delete`, `move`, `assign_category`, `tag_add`, `tag_replace`, `tag_remove`, `tag_clear` (max. 20 Tags à 40 Zeichen), `alt_text_update` (max. 255 Zeichen je Datei).

### Verwendungsnachweis

Spalte „Verwendet in“, Filter `usage_filter` und die Löschwarnung im Papierkorb lesen aus dem dauerhaften
Verwendungsindex (Tabelle `media_usage`), sobald er einmal vollständig aufgebaut ist (*Medien-Werkzeuge →
Verwendungsindex neu aufbauen*). Erfasst werden Beiträge und Seiten (Beitragsbild, Inhalt DE/EN, Auszug; bei Editor.js
getrennt nach Galerie- und Anhang-Blöcken), Hub-Sites (Einstellungen, Karten), Downloads (Titelbild, Beschreibungen),
Formulare (Felder, Einstellungen) und die Einstellungen des aktiven Themes („Design“). Erzeugte Größen und WebP/AVIF-
Fassungen zählen für ihr Original. Bis zum ersten Aufbau sucht die Mediathek wie bisher nur in Beiträgen und Seiten und
weist beim Filtern darauf hin. Danach hält jedes Speichern den Index aktuell. Die Extraktion übernimmt `Media\MediaUsageIndex` mit `Media\MediaPathNormalizer` (erkannt werden `/uploads/…`, absolute URLs der eigenen Domain, `/media-file?path=…`, `srcset` und CSS-`url()`; fremde Domains zählen nie) und je Inhaltsart einem `Media\MediaReferenceExtractor` (`Usage/ContentTableReferenceExtractor`, `DownloadReferenceExtractor`, `FormReferenceExtractor`, `HubSiteReferenceExtractor`, `ThemeReferenceExtractor`). Kontexte der Tabelle `media_usage`: `content`, `content_en`, `featured`, `gallery`, `attachment`, `setting`. Weitere Inhaltsarten melden Plugins über
die Action `cms_register_media_reference_extractors` an (`$index->register(new MeinExtractor())`).

#### Bilder in Inhalten: AVIF, WebP und `<picture>`

Ist „AVIF zusätzlich erzeugen“ (`auto_avif`) an, legt der Upload nach der WebP-Fassung je Bildgröße (Original, `-small`, `-medium`, `-large`, `-banner`) eine AVIF-Datei an (`Media\AvifDerivativeService`). Sie bleibt nur, wenn sie kleiner ist als die WebP-Fassung derselben Größe; Bilder über 25 Megapixel werden übersprungen. Beim Speichern prüft eine Probe-Kodierung, ob GD wirklich AVIF schreiben kann (Option `media_capabilities`). Bestehende Bilder holt die Karte „AVIF nachgenerieren“ unter *Medien-Werkzeuge* nach (`Media\AvifRegenerationService`, Auftrag `media_avif`, [MEDIA-TOOLS.md](MEDIA-TOOLS.md)). Nach jeder erzeugten Fassung läuft die Action `cms_media_derivative_created`.

Editor.js-Bild, Galerie und Medien-Text sowie die `<picture>`-Vorlagen von `cms-default` geben `<picture>` aus, sobald eine AVIF- oder WebP-Fassung existiert: zuerst `<source type="image/avif">`, dann `<source type="image/webp">`, danach das ursprüngliche `<img>` als Rückfall, nur für tatsächlich vorhandene Dateien. Das gilt auch für bestehende WebP-Fassungen (`auto_webp` ist standardmäßig an). Für `<picture>` gilt `display: contents` (`editorjs-content.css`), damit das Layout unverändert bleibt. **Hinweis für eigene Theme-Regeln:** Kindselektoren wie `figure > img` greifen nicht mehr, weil `<img>` nun in `<picture>` steht; Nachfahren-Selektoren (`figure img`) wirken weiter.

**Löschen:** *Löschen* in der Mediathek entfernt zusätzlich die erzeugten Fassungen eines Bildes. Größen (`-small` …), WebP und AVIF. Dateien gleichen Namens, die selbst hochgeladen wurden, bleiben stehen. Der Verwendungsindex erfasst die Fassungen über ihr Original (nur, wenn das Original existiert).

### Tab „Beitrags- & Site-Medien“ (`featured`)

Listet alle Dateien, die als Beitragsbild von Seiten oder Beiträgen genutzt werden, mit Anzahl der Verwendungen und fehlenden Dateien. Filter `usage_scope` = `all` | `posts` | `pages`. Von hier aus kann ein Bild für alle Verwendungen ersetzt werden; nach dem Ersetzen wird die Datei hervorgehoben (`?highlight=…&replaced=1`).

### Tab „Medien-Check“ (`check`)

Nur lesend: zeigt Beiträge und Seiten **ohne** Beitragsbild oder mit **defekter** Medienreferenz und verlinkt in den passenden Editor bzw. Ersetzen-Dialog.

### Tab „Kategorien“ (`categories`)

Eigene Kategorien anlegen/löschen (Name und Slug max. 80 Zeichen). Systemkategorien (`themes`, `plugins`, `assets`, `fonts`, `dl-manager`, `form-uploads`, `member`) sind geschützt.

### Tab „Einstellungen“ (`settings`)

| Gruppe | Option | Standard |
|---|---|---|
| Upload | `max_upload_size` | `64M` (1–256 MB) |
| | `allowed_types` | `image`, `document`, `archive`, `video`, `audio` |
| Dateinamen | `sanitize_filenames`, `unique_filenames`, `lowercase_filenames` | an, an, aus |
| Ablage | `organize_month_year` (Unterordner `JJJJ/MM`) | aus |
| Bilder | `auto_webp` (WebP-Variante), `strip_exif`, `jpeg_quality` (60–100), `max_width`/`max_height` (bis 8000 px) | an, an, 85, 2560/2560 |
| | `auto_avif` (AVIF zusätzlich erzeugen), `avif_quality` (1–100), `avif_speed` (0–10) | aus, 50, 6 |
| Vorschaubilder | `generate_thumbnails`; Größen small 150×150, medium 300×300, large 1024×1024, banner 1200×400 | aus |
| Sicherheit | `block_dangerous_types`, `validate_image_content`, `require_login_for_upload`, `protect_uploads_dir` | alle an |
| Mitglieder | `member_uploads_enabled`, `member_max_upload_size` (`5M`), `member_allowed_types` (`image`, `document`), `member_delete_own` | aus |

`protect_uploads_dir` schreibt eine `.htaccess` in `CMS/uploads/` (Apache): `Options -Indexes`, `X-Content-Type-Options: nosniff`, Download-Disposition für alle Nicht-Bilddateien, Inline-Auslieferung mit Cache-Header (Browser-Cache aus *Performance → Einstellungen*) für Bildformate. Wird die Option deaktiviert, entfernt das CMS die Datei wieder. Unter nginx müssen gleichwertige Regeln in der Serverkonfiguration gesetzt werden.

### Metadaten, Ausrichtung und Transparenz

`strip_exif` („Metadaten entfernen (EXIF, GPS, XMP, IPTC)“, Standard an; in *Performance → Medien* als `perf_strip_exif`) gilt für JPEG, PNG und WebP auf allen Upload-Wegen. Wie entfernt wird, hängt davon ab, ob der Weg das Original erhält:

| Upload-Weg | Original | Mit `strip_exif` |
|---|---|---|
| Mediathek (`uploadManagedFile()`), Mitgliederbereich (`FileUploadService`), Bild ersetzen (`replaceManagedFile()`), Editor.js-Bildblock (`uploadFile(…, true)`) | bleibt erhalten | Metadaten werden ohne Neukodierung entfernt (`Media\ImageMetadataStripper`). Die komprimierten Bilddaten bleiben Byte für Byte gleich, Qualität und Farbtiefe ändern sich nicht. |
| Theme-Logos (`uploadFile($file, 'theme-logos')`), Editor.js-Dateianhänge und andere Aufrufe von `uploadFile()` ohne Originalschutz | wird neu geschrieben | JPEG und PNG werden mit GD neu kodiert (aufrecht gedreht, Transparenz erhalten, ohne Metadaten). WebP sowie JPEG/PNG, die GD nicht lesen oder schreiben kann, werden wie oben ohne Neukodierung bereinigt. |

`ImageMetadataStripper` arbeitet an JPEG-Markern und PNG-/WebP-Blöcken statt mit GD. Entfernt werden EXIF (Kamera, Aufnahmezeit, GPS, eingebettetes Vorschaubild), XMP, IPTC, Kommentare, PNG-Textblöcke (`tEXt`, `zTXt`, `iTXt`, `tIME`, `eXIf`) und Daten nach JPEG-EOI, PNG-IEND oder dem RIFF-Block. Darstellungsrelevant bleiben ICC-Farbprofil, Gamma/Farbraum, Pixeldichte, Alphakanal, Animation und die **komprimierten Bilddaten selbst**. Bei einer Ausrichtung 2–8 wird aus dem EXIF nur ein minimales Ausrichtungs-Tag wieder eingesetzt, damit das nicht neu kodierte Original weiter aufrecht dargestellt werden kann. Urheber und Copyright liest die Vorbelegung (`UploadHandler`) vor der Bereinigung aus. Ist `strip_exif` aus, bleiben erhaltene Originale unverändert.

**Ausrichtung:** GD verwirft beim Kodieren alle Metadaten. Vor jedem Neukodieren, Skalieren und vor dem Erzeugen von WebP-, AVIF- und Vorschaubildern wendet `Media\ImageOrientation` die EXIF-Ausrichtung eines **JPEG** auf die Pixel an. `read()` prüft die JPEG-Signatur und nutzt `exif_read_data`, daher nur mit `ext-exif`; ohne Erweiterung oder bei Fehlern gilt Orientierung 1. `apply()` bildet alle acht Werte ab, einschließlich horizontaler/vertikaler Spiegelungen und der Drehungen; Werte 5–8 tauschen Breite und Höhe. Hochformat-Fotos erscheinen dadurch in allen neu kodierten Fassungen aufrecht und ihre Maximalmaße gelten nach der Korrektur. `ImageService`, `ImageProcessor` und `AvifDerivativeService` verwenden denselben Dienst. GD bzw. `exif_read_data` lesen in diesem Weg weder PNG-`eXIf` noch WebP-EXIF; dort kann nur das unbehandelte, erhaltene Original auf sein Ausrichtungs-Tag vertrauen.

**Transparenz:** Vor dem Speichern setzt die Verarbeitung `imagesavealpha(true)`; der gemeinsame Neu-Kodierpfad deaktiviert zusätzlich `imagealphablending`. RGBA-PNGs wie Logos bleiben transparent, auch nach Drehen, Skalieren oder Bildersatz. Halbtransparente Pixel bleiben erhalten; Palettenbilder behalten ihren transparenten Index. Dasselbe Alpha-Setup wird beim WebP-/AVIF-Derivat angewendet.

### Nachträgliche Bildverarbeitung

Über die Jobs `start_media_processing_job` / `process_media_processing_job` / `cancel_media_processing_job` können bestehende Bilder nachträglich verarbeitet werden. Modi: `all`, `webp`, `thumbnails`. Der Job arbeitet in Schritten zu 5 Dateien, höchstens 1000 Kandidaten und überspringt Dateien über 1 MB. Fortschritt wird per AJAX abgefragt; ein Abbruch ist jederzeit möglich. Alle Vorschaubilder nach geänderten Größen neu erzeugen, mit Fortschritt und Fortsetzung im Cron, geht unter *Medien → Medien-Werkzeuge* ([MEDIA-TOOLS.md](MEDIA-TOOLS.md)); dort stehen auch die Schalter für Urheberangaben.

### Upload-Prüfung

1. Anzahl (≤ 20) und Gesamtgröße (≤ 100 MB) des Batches, Dateinamenslänge (≤ 180).
2. Erweiterung gegen `allowed_types`; gefährliche Endungen (`php`, `php3`–`php5`, `phtml`, `phar`, `exe`, `com`, `bat`, `cmd`, `ps1`, `sh`, `pl`, `cgi`, `jar`, `msi`, `vbs`, `scr`, `dll`, `asp`, `aspx`, `jspx`) werden abgelehnt, solange `block_dangerous_types` aktiv ist.
3. MIME-Typ via `finfo` gegen die Erweiterung; Bilder werden bei `validate_image_content` dekodiert.
4. Dateiname bereinigen und eindeutig machen, dann Bildverarbeitung (Metadaten entfernen und EXIF-Ausrichtung anwenden, siehe „Metadaten, Ausrichtung und Transparenz“; skalieren, WebP, Thumbnails).

### Auslieferung geschützter Dateien

`GET /media-file?path=<relativ>&disposition=inline|attachment` prüft Pfad (keine `..`, keine versteckten Segmente), Lesbarkeit und, für `member/user-<id>/…`, dass der angemeldete Benutzer Eigentümer oder Administrator ist. Der alte Endpunkt `/media-proxy.php` leitet weiter.

### Verwandte Dokumente

[README.md](README.md) · [../performance/PERFORMANCE.md](../performance/PERFORMANCE.md)

### Tests

```bash
php TESTS/media-images/run.php
php TESTS/community-uploads/run.php
```

Die Tests prüfen unter anderem alle acht EXIF-Orientierungen, den JPEG/PNG/WebP-Verlustfrei-Stripper, JPEG-only-EXIF-Lesen, ICC-/Ausrichtungserhalt, Pixelbytegleichheit, Uploadpfade und Alpha nach Neu-Kodierung, Skalierung, Rotation und Bildersatz.
