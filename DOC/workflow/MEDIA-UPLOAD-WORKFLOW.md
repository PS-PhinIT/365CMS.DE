# Workflow: Medien hochladen

> **Stand:** 2026-11-23 | **Version:** 4.2.0

1. Datei über Mediathek, Mitgliederbereich oder einen unterstützten Editor-Block hochladen.
2. `FileUploadService`, `ImageService` und `MediaDeliveryService` prüfen Typ, Ziel, Größe und Berechtigung.
3. Bilder können EXIF-orientiert, WebP-/AVIF-Derivate erzeugt und ohne Neukodierung von Metadaten befreit werden; PNG-Transparenz bleibt erhalten.
4. Inhalte referenzieren nur öffentliche freigegebene Medien. Private Uploads laufen über geschützte Auslieferung, nicht über direkt lesbare Verzeichnisse.
5. Umbenennen, Verschieben und Löschen berücksichtigen den Medienverwendungsindex. Vor destruktiven Aktionen die Warnung prüfen.
6. Mit `php TESTS/media-images/run.php`, `php TESTS/media-avif/run.php` und `php TESTS/media-usage/run.php` gezielt prüfen.

Bedienhinweise: [../admin/media/README.md](../admin/media/README.md).

