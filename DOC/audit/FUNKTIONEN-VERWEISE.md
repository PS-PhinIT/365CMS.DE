# 365CMS – Gesamtaudit 2026-10-14 | Funktionen und Verweise

> Übersicht: [README.md](README.md) · Vorheriger Stand: [../checks/FUNKTIONEN.md](../checks/FUNKTIONEN.md), [../checks/VERWEISE.md](../checks/VERWEISE.md)

## English (summary)

**Functions:** Five settings or form fields had no effect, and all five are fixed:

- the global "enable comments" switch,
- the `robots.txt` content under general settings,
- category descriptions,
- tag descriptions,
- the importer's report download link.

**References:** Six broken references are fixed. Most sat in error paths and turned a handled error into a fatal error:

- `\WP_Error` and `use WP_Error` resolved to non-existent global classes.
- `SystemInfoModule::formatBytesLabel()` was missing.
- `MailSettingsActionResult::failure()` was missing.
- `BackupService::uploadToS3WithSDK()` was missing.
- A unique key existed only as a migration that the installer never runs.

## Deutsch

### Fehlende oder unvollständige Funktionen

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| FUN-21 | 🟠 | **„Kommentare aktivieren“ ohne Wirkung.** *Einstellungen → Allgemein* speichert `comments_enabled`, doch weder `CommentService` noch das Theme werteten den Wert aus. Kommentare blieben trotz Abschaltung möglich. | `CommentService::areCommentsEnabledGlobally()` (Standard: an). Geprüft in `isCommentablePost()`, sodass auch POST-Anfragen abgewiesen werden. `blog-single.php` blendet das Formular aus; vorhandene freigegebene Kommentare bleiben sichtbar. |
| FUN-22 | 🟡 | **`robots.txt` aus den Einstellungen ignoriert.** Das Feld `robots_txt` (*Einstellungen → Allgemein*) wurde gespeichert, `SeoSitemapService::generateRobotsTxt()` las aber nur den Inhalt aus *SEO → Technik* (`seo_robots_txt_content`). | Fallback auf `robots_txt`, wenn im SEO-Bereich nichts hinterlegt ist. |
| FUN-23 | 🟡 | **Kategorie-Beschreibung.** Das Feld im Seitenpanel wurde weder geladen noch gespeichert, obwohl `category.php` des Themes die Beschreibung ausgibt. Die Felder „Farbe“ und „Sofort veröffentlichen“ hatten kein Gegenstück in Datenbank oder Logik. | Beschreibung wird geladen, gespeichert (Klartext, max. 2.000 Zeichen) und nach einem Validierungsfehler wiederhergestellt. Die wirkungslosen Felder sind entfernt. Spalte `description` wird bei Bedarf ergänzt. |
| FUN-24 | 🟡 | **Tag-Beschreibung.** Gleiches Bild wie FUN-23: Das Feld wurde nicht gespeichert, `tag.php` gibt `description` aber aus. „Sofort veröffentlichen“ hatte keine Wirkung. | Wie FUN-23. |
| FUN-25 | 🟡 | **Importer: Bericht herunterladen.** Die Links in Protokoll und Ergebnisseite zeigten per GET auf `action=download_report`. Der Handler war nur als AJAX-Aktion registriert, der Link lieferte deshalb die normale Seite. | GET-Dispatch in `render_import_page()` vor jeder Ausgabe. Sichere Auslieferung siehe SEC-19. |

### Fehlerhafte Verweise

| ID | Schwere | Befund | Korrektur |
|---|---|---|---|
| REF-06 | 🟠 | `MediaModule` (Namespace `CMS\Admin\Modules\Media`) prüfte `instanceof \WP_Error`. Die Klasse heißt `CMS\WP_Error`, eine globale `WP_Error` existiert nicht. Fehler beim Upload wurden dadurch nicht erkannt. | `WP_Error` über den vorhandenen `use`-Import. |
| REF-07 | 🟠 | `StatusService` importierte `use WP_Error;` (global). Jeder Fehlerpfad, der ein `WP_Error` erzeugt, endete deshalb in „Class not found“. | `use CMS\WP_Error;`. |
| REF-08 | 🟠 | `SystemInfoModule::buildWarningCenterEntry()` rief `$this->formatBytesLabel()` auf, die Methode existierte nicht. Die Warnzentrale brach mit einem Fatal Error ab, sobald eine Speicherwarnung anfiel. | Methode ergänzt (B/KB/MB/GB). |
| REF-09 | 🟡 | `admin/mail-settings.php` nutzt `MailSettingsActionResult::failure()` im `default`-Zweig, die Methode fehlte. | Statische Fabrikmethode ergänzt. |
| REF-10 | 🟠 | `BackupService::uploadToS3()` rief bei installiertem AWS-SDK `uploadToS3WithSDK()` auf. Die Methode existiert nicht. Weil nur `\Exception` abgefangen wurde, endete der Backup-Upload mit einem Fatal Error. | Immer der vorhandene, signierte REST-Upload; `catch (\Throwable)`. |
| REF-11 | 🟡 | Der Installer legt das Schema über `SchemaManager` an und überspringt `MigrationManager`. Schlüssel, die nur per Migration entstehen (hier `uq_user_meta`), fehlten bei Neuinstallationen. | Schlüssel in `SchemaManager` (Tabellendefinition und Laufzeitprüfung). **Regel für künftige Änderungen:** Schemaänderungen immer auch in `SchemaManager` nachziehen. |

### Geprüft ohne Befund

- Alle `require`/`include` mit festen Pfaden zeigen auf vorhandene Dateien.
- Admin-Routen der Seitenleiste existieren als Einstiegsdateien.
- Die Behauptung „`cmsConfirm`/`cmsAlert` nie definiert“ aus der automatischen Analyse ist falsch; beide sind in `admin/partials/footer.php` definiert.

### Offene Empfehlungen

Siehe [README.md](README.md):

- OFFEN-07: gelesene, aber nicht einstellbare Optionen.
- OFFEN-08: tote private Methoden.
- OFFEN-09: Pfade `CMS/DOC/…` im alten Changelog.
