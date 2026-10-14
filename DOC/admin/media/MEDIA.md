# 365CMS – Projektdokumentation | Abschnitt: Admin – Medienverwaltung

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/media` | **Capability:** `manage_media` | **CSRF-Aktion:** `admin_media`

## English (summary)

The media manager at `/admin/media` (`CMS/admin/media.php` → `CMS/admin/modules/media/MediaModule.php`) is a file-system based library under `CMS/uploads/` with metadata (categories, tags, alt texts) in `CMS/config/media-meta.json` and settings in `CMS/config/media-settings.json`.

- Tabs: `library` (default), `featured`, `check`, `categories`, `settings`.
- Actions: `upload`, `replace_item`, `replace_items`, `create_folder`, `rename_item`, `move_item`, `delete_item`, `bulk_items`, `assign_category`, `add_category`, `delete_category`, `save_filter_preset`, `delete_filter_preset`, `save_settings`, `start/process/cancel_media_processing_job`.
- Upload batch: max. 20 files and 100 MB per request, file names max. 180 characters.
- Delivery of protected files goes through `GET /media-file?path=…` (`MediaDeliveryService`).

## Deutsch

### Architektur

| Schicht | Datei | Aufgabe |
|---|---|---|
| Einstieg | `CMS/admin/media.php` | Aktions-Allowlist, Upload-Grenzen, Tab-Routing |
| Modul | `CMS/admin/modules/media/MediaModule.php` | Listen, Filter, Dateiaktionen, Einstellungen, Verarbeitungsjobs |
| Views | `CMS/admin/views/media/library.php`, `featured.php`, `check.php`, `categories.php`, `settings.php` | Tabs |
| Services | `CMS/core/Services/MediaService.php` (Fassade), `Media/MediaRepository.php`, `Media/UploadHandler.php`, `Media/ImageProcessor.php` | Dateisystem, Metadaten, Upload-Validierung, Bildverarbeitung |
| Nutzung | `CMS/core/Services/MediaUsageService.php` | Wo wird eine Datei verwendet (Beitragsbilder, Inhalte) |
| Auslieferung | `CMS/core/Services/MediaDeliveryService.php` → `GET /media-file` | Geschützte Dateien, Member-Uploads |
| Upload-API | `ApiRouter`: `POST /api/upload`, `GET\|POST /api/media` | Editor- und Plugin-Uploads |
| Metadaten | `CMS/config/media-meta.json` | Kategorien, Tags, Alt-Texte, Filter-Presets |
| Einstellungen | `CMS/config/media-settings.json` | siehe Tab „Einstellungen“ |

### Tab „Bibliothek“ (`library`)

- **Navigation:** Ordnerbaum über `?path=`; Ansicht `?view=list|grid`.
- **Filter:** Suche `q` (max. 120 Zeichen), `file_type` (`image`, `document`, `video`, `audio`, `archive`, `other`), `extension`, `size_filter` (`tiny` … `huge`), `modified_filter` (`today`, `7d`, `30d`, `year`), `usage_filter` (`used`, `unused`), `category`, `orphan_days` (0/30/90/180/365 Tage ungenutzt).
- **Filter-Presets:** Bis zu 8 benannte Filterkombinationen speichern (`save_filter_preset`, Name max. 60 Zeichen).
- **Ordner `member/`:** enthält Uploads von Mitgliedern. Das Öffnen verlangt eine Bestätigung (`confirm_member=1`).
- **Einzelaktionen:** Upload, Ersetzen (gleicher Dateiname, Verweise bleiben gültig), Umbenennen, Verschieben, Löschen, Kategorie zuweisen.
- **Sammelaktionen (`bulk_items`):** `delete`, `move`, `assign_category`, `tag_add`, `tag_replace`, `tag_remove`, `tag_clear` (max. 20 Tags à 40 Zeichen), `alt_text_update` (max. 255 Zeichen je Datei).

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
| Vorschaubilder | `generate_thumbnails`; Größen small 150×150, medium 300×300, large 1024×1024, banner 1200×400 | aus |
| Sicherheit | `block_dangerous_types`, `validate_image_content`, `require_login_for_upload`, `protect_uploads_dir` | alle an |
| Mitglieder | `member_uploads_enabled`, `member_max_upload_size` (`5M`), `member_allowed_types` (`image`, `document`), `member_delete_own` | aus |

`protect_uploads_dir` schreibt eine `.htaccess` in `CMS/uploads/` (Apache): `Options -Indexes`, `X-Content-Type-Options: nosniff`, Download-Disposition für alle Nicht-Bilddateien, Inline-Auslieferung mit Cache-Header (Browser-Cache aus *Performance → Einstellungen*) für Bildformate. Wird die Option deaktiviert, entfernt das CMS die Datei wieder. Unter nginx müssen gleichwertige Regeln in der Serverkonfiguration gesetzt werden.

### Nachträgliche Bildverarbeitung

Über die Jobs `start_media_processing_job` / `process_media_processing_job` / `cancel_media_processing_job` können bestehende Bilder nachträglich verarbeitet werden. Modi: `all`, `webp`, `thumbnails`. Der Job arbeitet in Schritten zu 5 Dateien, höchstens 1000 Kandidaten und überspringt Dateien über 1 MB. Fortschritt wird per AJAX abgefragt; ein Abbruch ist jederzeit möglich.

### Upload-Prüfung

1. Anzahl (≤ 20) und Gesamtgröße (≤ 100 MB) des Batches, Dateinamenslänge (≤ 180).
2. Erweiterung gegen `allowed_types`; gefährliche Endungen (`php`, `php3`–`php5`, `phtml`, `phar`, `exe`, `com`, `bat`, `cmd`, `ps1`, `sh`, `pl`, `cgi`, `jar`, `msi`, `vbs`, `scr`, `dll`, `asp`, `aspx`, `jspx`) werden abgelehnt, solange `block_dangerous_types` aktiv ist.
3. MIME-Typ via `finfo` gegen die Erweiterung; Bilder werden bei `validate_image_content` dekodiert.
4. Dateiname bereinigen und eindeutig machen, dann Bildverarbeitung (EXIF entfernen, skalieren, WebP, Thumbnails).

### Auslieferung geschützter Dateien

`GET /media-file?path=<relativ>&disposition=inline|attachment` prüft Pfad (keine `..`, keine versteckten Segmente), Lesbarkeit und – für `member/user-<id>/…` – dass der angemeldete Benutzer Eigentümer oder Administrator ist. Der alte Endpunkt `/media-proxy.php` leitet weiter.

### Verwandte Dokumente

[README.md](README.md) · [../performance/PERFORMANCE.md](../performance/PERFORMANCE.md) · [../../workflow/MEDIA-UPLOAD-WORKFLOW.md](../../workflow/MEDIA-UPLOAD-WORKFLOW.md)
