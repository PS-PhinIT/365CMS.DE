# AP-06 – Uploads, Medien, Dateien, Backups (Version 4.2.6)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank. Maßstab: [Audit-Katalog, AP-06](../AUDIT-KATALOG-4.2.0.md#ap-06-uploads-medien-dateien-backups). Der Bericht betrifft nur AP-06; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Ein Befund (MEDIUM, umgesetzt): Rasterbilder wurden ohne Pixelgrenze dekodiert (Dekompressionsbombe). Die übrigen gelesenen Pfade sind in Ordnung; drei Punkte bleiben teilweise offen.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 06.1 Typ | ja (Stichprobe) | Endung kleingeschrieben, Allowlist über Typgruppen, MIME aus dem Inhalt gegen die Endung (`CMS/core/Services/MediaService.php:528–603`); SVG gesperrt (`:23`); gefährliche Endungen und innere Doppelendungen blockiert, der Schalter ist fest eingeschaltet (`:391`, `:664`); Dateiname ohne Steuerzeichen und Punkte, Endung auf Buchstaben und Ziffern begrenzt (`CMS/core/Services/Media/UploadHandler.php:344–380`). | Dateien ohne bekannte MIME-Zuordnung (z. B. PDF) werden nur über die Endung zugelassen; Gegenprobe mit präparierten Dateien steht aus. |
| 06.2 Bilder | ja, mit Befund | Maße vor Pixelgrenze: Befund TMP-AP06-1. EXIF-Ausrichtung mit allen acht Werten (`CMS/core/Services/Media/ImageOrientation.php`), verlustfreier Metadaten-Strip (`ImageMetadataStripper.php`), AVIF mit eigener Grenze von 25 Megapixeln (`AvifDerivativeService.php:37`). | Animation und Alpha-Erhalt nicht neu geprüft (bestehende Medien-Suiten laufen grün). |
| 06.3 Ablage | ja (Stichprobe) | Bereinigter Name plus eindeutiger Zusatz (`UploadHandler.php:createTargetPath`), `uploads/.htaccess` sperrt Skript- und versteckte Dateien (`CMS/uploads/.htaccess`), private Dateien über die Auslieferung mit Rechteprüfung. | Hosts ohne `.htaccess`-Auswertung (nginx) gehören zu AP-18. |
| 06.4 Auslieferung | ja | `Range`-Auswertung per Aufruf: `bytes=0-`, `bytes=-5`, `5-2`, `0-0,5-9`, `-0`, Überlauf, Groß-/Kleinschreibung, leere Datei liefern gültige Bereiche oder Fehler (`MediaDeliveryService.php:743–800`, `DownloadSecurity::parseRange`); Dateiname im Header ohne Zeilenumbruch und Anführungszeichen (`:buildSafeFilename`, `DownloadSecurity::contentDisposition`, `AttachmentController::disposition`). | Mehrfachbereiche liefern 416 statt der vollen Datei (zulässig, INFO). |
| 06.5 Archive, Importe | ja (Stichprobe) | Prüfer mit Anzahl-, Namens-, Größen- und Pfadregeln in `PluginManager.php:743–790`, `UpdateService.php:2011–2040`, `BackupService.php:1712–1760`, `ContentExchangeService.php:598–`, `DocumentationGithubZipSync.php:429–470` (Prüfung in AP-03 gelesen). | Keine präparierten Archive ausgeführt; `cms-importer` nicht geprüft. |
| 06.6 Kontingente, Verwaiste | offen | Nicht geprüft. | Wettlauf Upload ↔ Zuordnung, Aufräumen nach 24 Stunden (Community). |
| 06.7 Backups | offen (Teil bestätigt) | Offsite-Aufbewahrung löscht nur Backups aus der eigenen Statustabelle, nie das neueste (`CMS/core/Services/Backup/OffsiteBackupService.php:335–415`); Prüfsummen im Manifest (`BackupService.php:159–175`); Verschlüsselung mit eigenem Kontext (`CryptoService::CONTEXT_BACKUP`); `backups/.htaccess` verweigert den Zugriff. | Wiederherstellung mit manipuliertem Archiv; der Standardpfad liegt im Webroot (nur `.htaccess`), Hinweis im Admin vorhanden (`BackupService.php:2512`), AP-18. |

## Abweichungen

### TMP-AP06-1 · Rasterbilder ohne Pixelgrenze (MEDIUM, umgesetzt)

- **Fundstelle:** `CMS/core/Services/Media/ImageProcessor.php` (`generateThumbnails()`, `resizeToFit()`, `createImageResource()`), `CMS/core/Services/ImageService.php` (`loadImage()`), `CMS/core/Services/MediaService.php` (`loadGdImage()`, `validateFileTypeAndContent()`). Nur die AVIF-Ableitung hatte eine Grenze.
- **Ablauf:** Ein einfarbiges PNG mit 12 000 × 12 000 Pixeln ist 420 KB groß, hat ein gültiges Format und besteht Endung, MIME und Größenprüfung. `ImageProcessor::generateThumbnails()` liest nur die Maße aus dem Kopf und ruft dann `imagecreatefrompng()` auf. Gemessen: Spitzenspeicher 993 MB bei `memory_limit=128M`, weil GD den Pixelspeicher außerhalb des PHP-Limits belegt. Ein 30 000 × 30 000 großes PNG (wenige MB) bräuchte rund 5 GB.
- **Auswirkung:** Wer Bilder hochladen darf (Admin, Mitglieder bei aktivierten Mitglieder-Uploads), kann Arbeitsspeicher und Rechenzeit des Servers aufbrauchen. Kein Zugriff auf Daten.
- **Korrektur:** Neue Klasse `ImagePixelLimit` (Standard 50 Megapixel, mit `CMS_MAX_IMAGE_PIXELS` änderbar). Der Dateikopf entscheidet vor dem Dekodieren; der Upload lehnt zu große Bilder ab (`image_too_large`), alle vier Lader prüfen zusätzlich für bestehende Dateien.
- **Regression:** `TESTS/security/image-limits.php`: erzeugt ein 8000 × 7000 großes PNG (56 Megapixel, unter 1 MB), prüft `allowsFile`, Grenzwerte, `ImageProcessor` (Fehlercode `image_too_large`), den Erhalt normaler Bilder und die Aufrufe in den vier Ladern. Ohne die Korrektur schlagen sechs Prüfungen fehl. **Status:** umgesetzt. **Konfidenz:** hoch (gemessen).

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux. `media-images`, `media-avif`, `media-usage`, `community-uploads`, `s3-backup`, `security`: grün.

**Offen:** 06.6, Teile von 06.5 und 06.7, präparierte Archive und Bilder (Animation, Alpha, 16-Bit) im Browser bzw. mit MariaDB.
