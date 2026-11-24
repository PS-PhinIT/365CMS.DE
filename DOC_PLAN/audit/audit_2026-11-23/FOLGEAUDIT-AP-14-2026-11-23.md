# AP-14 – Barrierefreiheit und Oberfläche (Version 4.2.14)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27). Erstmals **mit laufender Seite**: PHP-Entwicklungsserver und
MariaDB 10.11 lokal, Chromium über Playwright, axe-core 4.10.2 mit den Regeln WCAG 2.0 und 2.1 Stufe A und AA. Die Testkopie lief mit gelockerter
PHP-Mindestversion (PHP 8.3.6 statt 8.4.1); das Repository blieb unverändert. Maßstab: [Audit-Katalog, AP-14](../AUDIT-KATALOG-4.2.0.md#ap-14-barrierefreiheit-und-oberflaeche).
Der Bericht betrifft nur AP-14; er ist keine Freigabe der gesamten Oberfläche. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Drei Befunde (MEDIUM bis LOW, umgesetzt): zu geringer Farbkontrast im Admin und in den Mitgliederseiten (151 Stellen auf 25 Seiten), horizontales
Scrollen der Mitgliederseiten bei 320 px und ein Link auf der Kontaktseite. Nach den Korrekturen meldet axe auf allen gemessenen Seiten keine Verstöße mehr.
Tastatur, Fokusführung und Bildschirmleser sind weiter nicht geprüft.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 14.1 axe-core | ja (Teil) | Alle WCAG-2.x-A/AA-Regeln auf 7 öffentlichen Seiten (Start, Anmeldung, Registrierung, Passwort vergessen, 404, Suche, Kontakt) und 8 Admin-Seiten (Übersicht, Beiträge, Seiten, Benutzer, Medien, Einstellungen, Kommentare, Formulare): vor den Korrekturen nur `color-contrast` auffällig. Die Regel `color-contrast` zusätzlich auf weiteren Admin-Seiten (Plugins, Themes, SEO, Analyse, Newsletter, Downloads, Weiterleitungen, Sicherungen, Protokolle, Rollen, Gruppen, Bestellungen) und drei Mitgliederseiten (Übersicht, Profil, Sicherheit): insgesamt 151 Stellen auf 25 Seiten; nach den Korrekturen 0 Stellen auf 23 Seiten, öffentlich 0 Verstöße. | Alle Regeln auf den übrigen Admin- und Mitgliederseiten, Beitrag und Seite mit Inhalt, Editor, Dialoge. |
| 14.2 Tastatur | offen | Skriptsuche: kein `tabindex` größer 0. | Reihenfolge, Fokusführung der Dialoge, Alternative zum Ziehen und Ablegen im Browser. |
| 14.3 Formulare | offen (Teil) | axe meldet auf Anmeldung und Registrierung keine fehlenden Beschriftungen. | `role=alert` bei Fehlern, Pflichtfelder, Zeitlimit des Captchas. |
| 14.4 Kontrast, Reflow | ja, mit Befunden | Befunde TMP-AP14-1 bis 3; Reflow bei 320 px auf Start, Anmeldung, Admin und zehn Mitgliederseiten gemessen. | Zoom 200 %, Textabstände, Dunkelmodus, `outline: none`-Regeln (11 Stellen) im Browser auf sichtbaren Fokus. |
| 14.5 Sprache | ja (Teil) | Kein `<html>` ohne `lang`; geprüfte `<img>` mit `alt`. | Feste Texte im Code, Datums- und Zahlenformate. |

## Abweichungen

### TMP-AP14-1 · Farbkontrast von Hilfstexten und Abzeichen im Admin und Mitgliederbereich (MEDIUM, umgesetzt)

- **Fundstelle:** Hilfstexte in `#94a3b8`, `#9ca3af`, `#737373`, `#6b7280`, `#64748b` auf hellen Flächen (`CMS/assets/css/admin.css`, `admin-dashboard.css`, Tabler `.text-muted`) sowie helle Abzeichen `bg-*-lt` und Statusfarben von Tabler (`CMS/assets/tabler/css/tabler.min.css`, setzt die Farbe mit `!important`).
- **Messung:** axe-core, Regel `color-contrast`, 151 Stellen auf 25 Seiten (Soll 4,5:1). Häufigste Gruppen: Meta-Texte `span` `#6b7280` auf `#f1f0ee` (32, 4,24:1), Reiter `a.nav-link` `#737373` (26, 4,16:1), Abzeichen `bg-blue-lt` (15, 4,0:1), `bg-azure-lt` (12, 2,7:1), Formularhinweise `.form-hint` `#9ca3af` auf Weiß (9, 2,4:1), `bg-success-lt` (9, 2,4:1), `bg-warning-lt` (3, 1,8:1), `btn-outline-warning` (2,0:1). Die Design-Tokens `--color-text-secondary` und `--color-text-tertiary` waren nirgends definiert; alle 15 Stellen mit `var(--color-text-*, #…)` nutzten den hellen Rückfallwert.
- **Auswirkung:** Hilfstexte, Zähler, Reiter und Statusabzeichen sind für Menschen mit eingeschränktem Sehvermögen schwer lesbar (WCAG 1.4.3).
- **Korrektur:** `admin.css`: die beiden Tokens definiert (`#4b5563`, `#596373`; mindestens 5,3:1 auf der dunkelsten Fläche `#f1f0ee`), betroffene Klassen und inaktive Reiter in `#596373`; neue gemeinsame Datei `assets/css/a11y-contrast.css` (von Admin- und Mitglieder-Kopf geladen): `bg-*-lt` und `.text-success` dunkler, Umriss-Buttons im Ruhezustand; `admin-dashboard.css`: Abschnittsüberschrift auf das Token. Der Admin hat keinen Dunkelmodus; die Werte gelten für helle Flächen.
- **Regression:** `TESTS/functions/a11y-contrast.php` rechnet den WCAG-Kontrast der Token-Werte gegen alle gemessenen Flächen und der 55-%-Mischung gegen die Tönung (blue, azure, green, success, warning, danger, secondary, primary) und prüft, dass beide Kopfdateien das Stylesheet laden; drei Mutanten (Token auf `#94a3b8`, Kopf ohne Datei, Reflow-Wert zurück) schlagen fehl. Nachmessung im Browser: 0 Stellen auf 23 Seiten. **Status:** umgesetzt. **Konfidenz:** hoch (gemessen vorher und nachher).

### TMP-AP14-2 · Mitgliederseiten scrollen bei 320 px seitlich (LOW, umgesetzt)

- **Fundstelle:** `CMS/member/*.php` (`<div class="row g-4">` unmittelbar im Container), `CMS/assets/css/member-dashboard.css`.
- **Messung:** Bei 320 px Breite 324 px Seitenbreite auf 8 von 10 Mitgliederseiten (Übersicht, Profil, Nachrichten, Sicherheit, Benachrichtigungen, Datenschutz, Abo, Medien). Ursache: Das Gutter `g-4` (1,5 rem) lässt die Zeile 12 px über den Containerrand ragen, der Rand ist auf schmalen Schirmen 8 px breit.
- **Auswirkung:** Verstoß gegen WCAG 1.4.10 (Reflow), horizontales Scrollen auf Mobilgeräten.
- **Korrektur:** Unter 576 px nutzen oberste Zeilen mit `g-4` ein Gutter von 1 rem (gleich dem Containerrand). **Regression:** Quelltextvertrag in `TESTS/functions/a11y-contrast.php` (Mutante erkannt); Nachmessung: alle zehn Seiten 320 von 320 px. **Status:** umgesetzt.

### TMP-AP14-3 · Link auf der Kontaktseite mit 2,8:1 (LOW, umgesetzt)

- **Fundstelle:** `CMS/themes/cms-default/contact.php:69` (eingebettetes Stylesheet): `.contact-aside dd a{color:var(--accent)}`; der Akzent `#c0862a` hat auf `#f2f1ec` nur 2,77:1, die Theme-Variable `--accent-text` (`#8f5f15`) ist dafür vorgesehen.
- **Korrektur:** `--accent-text` und Unterstreichung wie bei den übrigen Textlinks des Themes. **Regression:** Prüfung in `TESTS/functions/a11y-contrast.php`; Nachmessung 0 Verstöße. **Status:** umgesetzt.

## Hinweise ohne Änderung

- **INFO-1:** Die Theme-Seiten (Start, Anmeldung, Registrierung, 404, Suche) hatten schon vorher keine Verstöße; der Core-Teil (Theme `cms-default`) erfüllt axe auf den gemessenen Seiten.
- **INFO-2:** Von den Statusfarben als reinem Text (Tabler `.text-*`) trat nur `.text-success` auf (SEO-Seite, 2,7:1) und wurde mit `.text-green` angepasst; `.text-warning`, `.text-azure` und andere sind auf den gemessenen Seiten nicht als Text auf Weiß vorgekommen, auf anderen Seiten können sie es.

## Testlauf und Übergabe

Browserlauf mit Playwright 1.x und axe-core 4.10.2 gegen PHP-Entwicklungsserver und MariaDB 10.11; `php TESTS/run-all.php` grün.

**Offen:** 14.2 (Tastatur, Fokus), 14.3 im Einzelnen, Zoom 200 %, `outline: none`-Regeln, weitere Seiten mit Inhalt.
