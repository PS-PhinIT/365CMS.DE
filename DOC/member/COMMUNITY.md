# 365CMS Projektdokumentation | Mitglieder: Community (Forum)

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **Adressen:** `/forum`, `/forum/mitglieder/{id}`, `/member/plugin/community`

## English (summary)

Members read and write in the forum under `/forum` (English `/en/forum`), react, subscribe, report posts and manage their forum data in the member area (`/member/plugin/community`). The editor saves drafts locally in the browser and on the server, supports uploads by file picker, drag and drop and paste, and shows a live preview.

## Deutsch

### Lesen und Schreiben

- **Startseite** `/forum` zeigt Kategorien und Foren, **Forum** `/forum/{forum}` die Themen (angeheftete zuerst), **Thema** `/forum/thema/{id}-{titel}` die Beiträge in Seiten. Globale Listen: `/forum/neu`, `/forum/aktiv`, `/forum/unbeantwortet`, Suche `/forum/suche`.
- **Neues Thema, Antworten, Zitieren, Bearbeiten:** Schaltflächen im Forum bzw. am Beitrag. Beiträge sind Markdown; der visuelle Editor (SunEditor) wechselt zwischen Ansicht und Markdown. Bearbeiten ist im Bearbeitungsfenster (`edit_window_minutes`) möglich, frühere Fassungen bleiben erhalten.
- **Anhänge:** Datei wählen, ins Fenster ziehen oder einfügen (Bilder aus der Zwischenablage). Erlaubte Typen und Größe legt die Verwaltung fest. Nicht abgeschickte Anhänge werden nach 24 Stunden gelöscht.
- **Entwürfe:** Ihr Text wird laufend im Browser gesichert (30 Tage) und beim Neuen Thema und bei Antworten auch auf dem Server. Beim erneuten Öffnen der Seite erscheint der Entwurf wieder; abschicken oder verwerfen löscht ihn.
- **Vorschau:** zeigt den Beitrag so, wie er veröffentlicht wird (gereinigt, ohne Skripte).

### Reagieren, Abonnieren, Melden

- **Reaktionen** (Gefällt mir, Liebe, Lustig, Gute Idee) mit einem Klick; ein Klick auf die gewählte Reaktion nimmt sie zurück.
- **Abonnieren:** Thema oder Forum abonnieren; Benachrichtigungen kommen im Konto und, wenn eingeschaltet, per E-Mail (Abmeldelink in jeder Mail).
- **Melden:** am Beitrag „Melden“, Grund wählen. Die Moderation sieht die Meldung; bei mehreren Meldungen kann ein Beitrag bis zur Prüfung ausgeblendet werden.
- **Lösung:** in Q&A-Foren kann der Ersteller oder die Moderation eine Antwort als Lösung markieren.
- **Gelesen:** „Alles als gelesen markieren“ gilt global oder je Forum.

### Mitgliederbereich

`/member/plugin/community` hat die Reiter **Meine Themen**, **Meine Antworten**, **Abos** (beenden), **Einstellungen** (automatisch abonnieren, E-Mail, Benachrichtigungstypen, Sprache, Signatur) und, nur für Moderatoren, **Moderation** (Freigaben und Meldungen). Inhalte in Foren, die Sie nicht mehr lesen dürfen, erscheinen dort nicht.

### Profil

Das öffentliche Profil `/forum/mitglieder/{id}` zeigt Anzeigename, Rang, Beiträge und die Signatur. Gelöschte Konten erscheinen als „Gelöschtes Mitglied“.

### Datenschutz

Gespeichert werden Beiträge, Reaktionen, Abos, Lesestatus, Entwürfe und eine gekürzte, nicht umkehrbare Kennung der IP-Adresse (nur für Spam-Abwehr, 30 Tage). Über die Datenschutz-Anfrage erhalten Sie einen Datenexport. Bei der Kontolöschung werden Beiträge je nach Einstellung anonymisiert (Anzeige „Gelöschtes Mitglied“) oder gelöscht. Die Datenschutzerklärung der Website nennt Fristen und Zwecke im Abschnitt „Forum (Community)“.

### Barrierefreiheit

Die Seiten sind mit Tastatur und Screenreader bedienbar: Sprunglink, benannte Navigationen, Beiträge als Artikel, Reaktionen mit Namen und Zähler, Dialoge mit Fokusführung. Probleme bitte der Moderation melden.
