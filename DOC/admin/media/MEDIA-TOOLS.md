# 365CMS – Admin: Medien-Werkzeuge und Urheberangaben

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/media-tools` | **Capability:** `manage_media`

## English (summary)

*Media → Media tools* regenerates the thumbnails (and optionally WebP versions) of all images in the media library
in the current sizes – as a background job with progress, cancel and continuation by the hourly cron when the page is
closed. The page also holds the output switches for image credits (credit line in captions, Schema.org
`ImageObject`, prefill from EXIF/IPTC on upload). Author, license, source and “AI-generated” are maintained per image in
the media library.

## Deutsch

### Vorschaubilder neu erzeugen (#13)

Nötig, nachdem die Bildgrößen unter *Medien → Einstellungen* geändert wurden (klein, mittel, groß, Banner).

1. Die Seite zeigt die aktuellen Größen und die Zahl der Bilder (Originale; erzeugte Fassungen `-small`, `-medium` …
   zählen nicht).
2. Optional *WebP-Fassungen ebenfalls neu erzeugen*.
3. *Neu erzeugen* startet den Auftrag (`BatchJobService`, Typ `media_thumbnails`) mit Fortschritt, Pause und Abbrechen.
   Je Schritt höchstens 8 Bilder bzw. 8 Sekunden; höchstens 20 000 Bilder je Lauf.
4. Wird die Seite geschlossen, setzt der **stündliche Cron** den offenen Lauf fort (höchstens 45 Sekunden je Cron-Lauf).
   Ein offener Lauf erscheint beim nächsten Aufruf mit *Fortsetzen*.
5. Ergebnis: erzeugt, übersprungen, fehlgeschlagen; Fehler mit Dateipfad aufklappbar.

Originale bleiben unverändert. Start und Abbruch stehen im Audit-Log (`media.thumbnails.*`). Fehlt GD oder Imagick, ist
die Funktion gesperrt (siehe *Systeminfo → PHP-Erweiterungen*). Die ältere Stapelverarbeitung unter
*Medien → Einstellungen* (auch „nur WebP“) bleibt erhalten und verweist auf diese Seite.

JSON-Endpunkt: `/admin/media-tools-api` (POST, Token `admin_media_tools_api`): `start` (`webp=0|1`), `step`, `cancel`,
`status`.

### Urheberangaben (#11)

**Je Bild** (Mediathek → Bild → *Urheber und Lizenz*, auch als Sammelaktion für mehrere Dateien): Urheber, Lizenz (alle Rechte vorbehalten,
CC BY, CC BY-SA, CC BY-ND, CC BY-NC, CC BY-NC-SA, CC BY-NC-ND, CC0, gemeinfrei, eigene), Lizenz-URL, Quelle, Credit-Text,
Copyright und optional *KI-generiert* mit Werkzeug. Gespeichert in `config/media-meta.json`; gelten auch für die erzeugten
Größen und WebP-Fassungen.

**Ausgabe** (diese Seite):

| Schalter | Wirkung |
|---|---|
| Credit-Zeile in Bildunterschriften | `<small class="cms-media-credit">` in der `figcaption` von Einzelbild, Galerie und Karussell, z. B. „Bild: Erika Muster, CC BY 4.0 · KI-generiert (Werkzeug)“. Themes können sie je Bild über den Filter `cms_media_credit_line_enabled` abschalten |
| Schema.org-Angaben | ein JSON-LD-Block je Seite mit `ImageObject` (creator, license, copyrightNotice, creditText), nur im öffentlichen Frontend |
| Vorbelegung beim Hochladen | übernimmt Urheber und Copyright aus IPTC/XMP/EXIF |

Geprüft (4.0.0, Laufzeit): Angaben an einem Bild gesetzt → Beitrag zeigt Credit-Zeile mit Links (Quelle, Lizenz) und
JSON-LD; beide Schalter aus → keine Ausgabe, wieder an → Ausgabe. Vorschaubilder: Lauf im Browser (16 Bilder) und
Fortsetzung eines offenen Laufs im Cron.

### Dateien

`CMS/admin/media-tools.php`, `CMS/admin/media-tools-api.php`, `CMS/admin/views/media/tools.php`,
`CMS/assets/js/admin-media-tools.js`, `CMS/core/Services/Media/ThumbnailRegenerationService.php`,
`CMS/core/Services/Media/MediaLicenseService.php`, `CMS/core/Services/FeatureHooks/MediaFeatureHooks.php`
