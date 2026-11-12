# 365CMS – Admin: Formulare und Eingänge

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Forms are part of the core. *Content → Forms* (`/admin/forms`, capability `manage_forms`) offers a builder with 15
field types (DE/EN labels, width, validation rules, sorting by drag & drop or keyboard) and per-form settings
(recipients, subject, confirmation mail, success message or redirect, spam level, storage, retention). *Content →
Submissions* (`/admin/form-submissions`, `view_form_submissions`) lists submissions with filters, detail view, status,
trash and CSV export. Forms are shown via the Editor.js block “Form”, at `/contact/<key>` and via the API
`POST /api/contact/<key>/submit`. File uploads are checked by content, stored in private storage and can only be
downloaded in the admin area.

## Deutsch

### Formular anlegen

1. *Inhalte → Formulare → Neues Formular*: Titel und Schlüssel (Adresse `/contact/<schlüssel>`).
2. Felder im Baukasten hinzufügen: Text, E-Mail, Telefon, URL, Zahl, Datum, Textbereich, Auswahl, Radio, Checkbox,
   Einwilligung, versteckt, Datei, Überschrift, Absatz. Je Feld Beschriftung DE/EN, Platzhalter, Hilfe, Pflicht,
   Breite und Prüfregeln (Länge, Muster, Min./Max., Datumsbereich, Dateitypen).
3. Einstellungen je Formular: Empfänger, Betreff, Bestätigungsmail, Erfolgsmeldung oder Weiterleitung, Spam-Stufe,
   ob Eingänge gespeichert werden, Löschfrist.
4. Einbinden: Editor.js-Block *Formular* in Seite oder Beitrag (mit Server-Vorschau) oder Link auf `/contact/<schlüssel>`.

Globale Standards (Absender, Empfänger, Datenschutz-Link, Einwilligungstext, Upload-Grenzen, Löschfrist, Darstellung)
stehen unter *Formulare → Einstellungen*.

### Datei-Uploads

| Regel | Wert |
|---|---|
| Erlaubte Endungen | je Feld; Obergrenze ist die globale Liste (Standard: pdf, jpg, jpeg, png, gif, webp, txt, csv, docx, xlsx, odt, ods) |
| Immer gesperrt | ausführbare und aktive Inhalte (PHP, HTML, SVG, JavaScript, Skripte, Programme …), auch wenn eingetragen |
| Größe | je Feld (KB); global höchstens 5 MB als Standard, einstellbar bis 50 MB |
| Anzahl | je Feld 1–10 Dateien |
| Prüfung | Endung **und** Inhalt (`finfo`): eine als `.png` getarnte PHP-Datei wird abgelehnt |
| Ablage | privater Speicher `forms/uploads/<zufallsname>`, nicht per Adresse erreichbar |
| Abruf | nur im Admin unter *Eingänge* (Recht `view_form_submissions`), als Download mit `nosniff` und ohne Cache |

Der Browser prüft Typ, Größe und Anzahl schon vor dem Absenden; der Server prüft unabhängig davon erneut. Fehler
erscheinen am Feld und in der Zusammenfassung oberhalb des Formulars.

Geprüft (4.0.0, Laufzeit): gültiges PNG wird gespeichert und ist im Admin abrufbar (identischer Inhalt); getarnte
PHP-Datei, zu große Datei, falscher Typ, SVG und zu viele Dateien werden abgelehnt – im Browser und ohne JavaScript
direkt am Server; Abruf ohne Anmeldung leitet zum Login, direkter Pfad liefert 404.

### Eingänge

Liste mit Formularfilter, Status (neu, gelesen, erledigt), Suche, Sammelaktionen, Papierkorb und CSV-Export.
Detailansicht mit allen Angaben, Anhängen und Einwilligung. Endgültiges Löschen entfernt auch die Dateien.

### Datenschutz

- Keine IP-Adressen und User-Agents in Eingängen.
- Löschfrist global (Standard 365 Tage, 7–3650) und je Formular; der tägliche Cron löscht ältere Eingänge samt
  Dateien (Regel `forms.submissions` unter *Recht → Löschfristen*, [DATA-RETENTION.md](../legal/DATA-RETENTION.md)).
- DSGVO-Auskunft und -Löschung beziehen Eingänge über die E-Mail-Adresse ein; Mitglieder sehen ihre Anfragen unter
  *Kontaktanfragen*.

### Dateien

`CMS/admin/forms.php`, `CMS/admin/form-submissions.php`, `CMS/core/Services/Forms/*`,
`CMS/core/Services/FeatureHooks/FormHooks.php`
