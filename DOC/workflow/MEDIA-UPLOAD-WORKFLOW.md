# 365CMS – Projektdokumentation | Abschnitt: Workflow – Medien-Upload
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable | **Update:** 2026-10-02
> **Quellen:** `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, `CMS/core/Services/MediaService.php`, `CMS/core/Services/Media/UploadHandler.php`, `CMS/core/Services/Media/ImageProcessor.php`, `CMS/core/Services/FileUploadService.php`, `CMS/core/Services/MediaDeliveryService.php`, `CMS/core/Services/EditorJsService.php`

## English (summary)

There are three upload entry points: the admin media library (`/admin/media`, form POST), the generic JSON endpoint `POST /api/upload` (CSRF action `media_action`, field `file` or `filepond`, optional `target_path`) and the Editor.js endpoint `/api/media` (token `editorjs_media`). All of them end in `MediaService` → `UploadHandler`: extension allowlist, dangerous-extension block, `finfo` MIME check, image decoding, filename sanitising/uniqueness, then `ImageProcessor` (EXIF strip, resize, WebP, thumbnails). Members may upload only when `member_uploads_enabled` is on, only below `member/user-<id>/`, with their own size/type limits. Protected files are served via `GET /media-file`.

## Deutsch

### Einstiegspunkte

| Weg | Wer | Schutz | Ziel |
|---|---|---|---|
| `/admin/media` (Bibliothek, Upload-Formular) | Administrator | Admin-Shell, CSRF der Medienseite | frei wählbarer Ordner unter `CMS/uploads/` |
| `POST /api/upload` | angemeldet | CSRF `media_action` (Feld `csrf_token` oder Header `X-CSRF-Token`) | `target_path`/`path`; Mitglieder nur `member/user-<id>/…` |
| `GET/POST /api/media` | Editor.js im Admin | Token `editorjs_media` | Upload, Bibliothek, Remote-Bild |

### Ablauf `POST /api/upload`

1. Methode muss `POST` sein (`405 upload.invalid_method`).
2. Angemeldet? (`403 upload.unauthorized`)
3. CSRF `media_action` gültig? (`403 upload.invalid_csrf`)
4. Datei in `$_FILES['filepond']` oder `$_FILES['file']` (`400 upload.missing_file` / `upload.invalid_file`).
5. `target_path` bereinigen (`400 upload.invalid_target_path` bei `..`, versteckten Segmenten usw.).
6. **Nicht-Admins:** `member_uploads_enabled` muss aktiv sein (`403 upload.member_disabled`); leerer Pfad → `member/user-<id>`; anderer Pfad → `403 upload.member_path_denied`. Es gelten `member_max_upload_size` (Standard `5M`) und `member_allowed_types` (`image`, `document`).
7. Validierung (`MediaService::validateUploadFile()`), sonst `422 upload.validation_failed`.
8. Speichern (`uploadManagedFile()`), sonst `422 upload.persist_failed`.
9. Erfolg `200`:

```json
{
  "id": "2026/10/bild.webp",
  "filename": "bild.webp",
  "path": "2026/10/bild.webp",
  "parent_path": "2026/10",
  "url": "https://example.com/uploads/2026/10/bild.webp",
  "preview_url": "…",
  "download_url": "https://example.com/media-file?path=…&disposition=attachment",
  "new_token": "<neues media_action-Token>"
}
```

Fehler liefern `{"error":"…","new_token":"…"}` und werden über den Logger protokolliert. Das zurückgegebene Token immer für den nächsten Upload verwenden.

### Validierungsregeln (Admin-Upload)

| Prüfung | Regel |
|---|---|
| Batch | max. 20 Dateien, max. 100 MB gesamt, Dateiname max. 180 Zeichen |
| Größe | `max_upload_size` (Standard `64M`, 1–256 MB) und PHP-Limits (`upload_max_filesize`, `post_max_size`) |
| Typgruppen | `allowed_types` (`image`, `document`, `archive`, `video`, `audio`) |
| Gefährliche Endungen | `php`, `php3`–`php5`, `phtml`, `phar`, `exe`, `com`, `bat`, `cmd`, `ps1`, `sh`, `pl`, `cgi`, `jar`, `msi`, `vbs`, `scr`, `dll`, `asp`, `aspx`, `jspx` (bei `block_dangerous_types`) |
| Inhalt | MIME via `finfo` passend zur Endung; Bilder werden dekodiert (`validate_image_content`) |
| Dateiname | `sanitize_filenames`, `unique_filenames`, optional `lowercase_filenames`; Ablage in `JJJJ/MM` bei `organize_month_year` |

### Nachverarbeitung

`ImageProcessor`: EXIF entfernen (`strip_exif`), auf `max_width`/`max_height` (Standard 2560 px) skalieren, JPEG-Qualität (85), WebP-Variante (`auto_webp`), Vorschaubilder (`generate_thumbnails`: 150×150, 300×300, 1024×1024, 1200×400). Bestehende Dateien lassen sich nachträglich über den Verarbeitungsjob der Medienseite (Modi `all`, `webp`, `thumbnails`; 5 Dateien je Schritt, max. 1000 Kandidaten, Dateien > 1 MB werden übersprungen) bearbeiten.

### Auslieferung

- Öffentliche Dateien: direkt unter `/uploads/…`; `protect_uploads_dir` schreibt eine `.htaccess` (kein Directory-Listing, `nosniff`, Download-Disposition für Nicht-Bilder). Unter nginx gleichwertige Regeln selbst setzen.
- Geschützte/Mitgliederdateien: `GET /media-file?path=<relativ>&disposition=inline|attachment` prüft Pfad, Lesbarkeit und bei `member/user-<id>/…` Eigentümer oder Admin.

### Medien im Inhalt verwenden

1. Bild hochladen (Bibliothek oder direkt im Editor-Bildblock).
2. Alt-Text in der Bibliothek pflegen (Sammelaktion `alt_text_update`, max. 255 Zeichen).
3. Als Beitragsbild wählen oder im Editor einfügen.
4. Beim Ersetzen über „Ersetzen“ (gleicher Dateiname) bleiben alle Verweise gültig.
5. Vor dem Löschen Nutzung prüfen (`usage_filter=used`, Tab „Beitrags- & Site-Medien“).

### Bekannter Hinweis

Das Mitglieder-Medienmodul unter `/member/media` erzeugt sein Upload-Token seit 3.4.12 mit der Aktion `media_action`, die `/api/upload` prüft (vorher scheiterte der erste Upload mit `upload.invalid_csrf`; siehe [member/MEMBER-SECURITY.md](../member/MEMBER-SECURITY.md)). Uploads mit Doppelendungen wie `datei.php.jpg` werden seit 3.4.12 abgelehnt.

## Verwandte Dokumente

- [admin/media/MEDIA.md](../admin/media/MEDIA.md) · [admin/media/README.md](../admin/media/README.md)
- [core/API-REFERENCE.md](../core/API-REFERENCE.md) · [core/SECURITY.md](../core/SECURITY.md)
- [CONTENT-MANAGEMENT-WORKFLOW.md](CONTENT-MANAGEMENT-WORKFLOW.md)
