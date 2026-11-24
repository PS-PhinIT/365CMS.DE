# AP-13 – Fehlende Funktionen, Verweise, Konsistenz (Version 4.2.13)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank und ohne Browser. Maßstab: [Audit-Katalog, AP-13](../AUDIT-KATALOG-4.2.0.md#ap-13-fehlende-funktionen-verweise-konsistenz). Der Bericht betrifft nur AP-13; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund in Code und Sprachdateien. Beim Prüfen von 13.7 fehlten meine eigenen neuen Einträge in Inventaren und Konfigurationsdoku (AP-06); sie sind nachgetragen.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 13.1 Routen, Menüs | offen | Nicht neu geprüft; die Suiten `admin-menu`, `theme-contract` laufen grün. | Jeder Menüpunkt mit Handler und Recht. |
| 13.2 Hooks | ja (Teil) | 139 Hook-Namen als Zeichenkette im Code; alle bis auf 8 stehen in `DOC/core/HOOKS-REFERENCE.md`. Die 8: fünf `admin_ajax_cms_importer_*` (Plugin `cms-importer`), die dynamischen Präfixe `cms_init_<Modus>` (`Bootstrap.php:1030`) und `cms_localized_<Typ>_payload` (`ContentLocalizationService.php:324`) sowie `phinit_head_meta_data` (der Kern registriert einen Listener für diesen Filter des Themes, `SEOService.php:373`). | Verwaiste Listener (Referenz nennt Hooks ohne Auslöser) nicht geprüft. |
| 13.3 Einstellungen | offen | Nicht geprüft. | Option ohne Leser oder ohne Oberfläche. |
| 13.4 Sprachschlüssel | ja | `CMS/lang/de.yaml` und `en.yaml`: je 3 950 Schlüssel, keine nur auf einer Seite, keine abweichenden Platzhalter, keine leeren englischen Werte. | 1 106 Schlüssel ohne wörtlichen Verweis im Code (INFO-1). |
| 13.5 Verweise | ja (Stichprobe) | Alle 1 963 `use CMS\…`-Anweisungen außerhalb von `vendor`, `assets`, `plugins`, `themes` zeigen auf vorhandene Dateien, bis auf 35: 34 gehören dem Bündel `CMS\Vendor\League\CommonMark` (zwei davon per `class_exists()` über den Autoloader geladen) und eine dem Mitglieder-Controller, der vor der `use`-Anweisung eingebunden wird (`member/dashboard.php`). | Verweise auf Funktionen und Methoden nicht geprüft. |
| 13.6 Formulare, Buttons | offen | Nicht geprüft. | `data-*`-Ziele ohne Gegenstück. |
| 13.7 Doku | ja (Teil) | Links in `DOC` wurden mit der Doku-Überarbeitung geprüft. Neu: `Media/ImagePixelLimit` in `DOC/FILELIST.md` und `DOC/CMSFILESTRUCTUR.md`, Konstante `CMS_MAX_IMAGE_PIXELS` in `DOC/INSTALLATION.md` nachgetragen. | Vollständigkeit der Inventare für die Dateien aus AP-17 (`UserDataEraser`) und die übrigen Berichte. |
| 13.8 Platzhalter | ja | Keine `TODO`, `FIXME`, `XXX`, `HACK` im ausgelieferten PHP und JS von Kern, Admin, Mitgliederbereich, Installer und Themes. | – |

## Abweichungen

Keine im Code. Die Doku-Lücke (Inventare, Konfigurationskonstante) ist geschlossen.

## Hinweise ohne Änderung

- **INFO-1:** 1 106 von 3 950 Sprachschlüsseln kommen als Zeichenkette nirgends im Code vor, vor allem Gruppen `feeds.` (313), `community.` (167), `forms.` (94), `cms_events.` (88), `cms_experts.` (80), `newsletter.` (80). Viele werden zur Laufzeit zusammengesetzt (`'feeds.' . $name`) oder gehören zu Plugins, die nicht im Paket liegen. Bereinigung nur nach Laufzeit-Protokoll der aufgerufenen Schlüssel.

## Testlauf und Übergabe

Skriptsuchen (Hooks, Sprachdateien mit PyYAML, `use`-Auflösung); Gesamtlauf aller Suiten grün.

**Offen:** 13.1, 13.3, 13.6 vollständig; verwaiste Hook-Listener.
