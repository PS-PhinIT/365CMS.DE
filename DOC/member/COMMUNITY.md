# 365CMS Projektdokumentation | Mitglieder: Community (Forum)

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **Adressen:** `/forum`, `/forum/mitglieder/{id}`, `/member/plugin/community`

## English (summary)

Members read and write in the forum under `/forum` (English `/en/forum`), react, subscribe, report posts and manage their forum data in the member area (`/member/plugin/community`). The editor saves drafts locally in the browser and on the server, supports uploads by file picker, drag and drop and paste, and shows a live preview.

## Deutsch

### Lesen und Schreiben

- **Startseite** `/forum` zeigt Kategorien und Foren, **Forum** `/forum/{forum}` die Themen (angeheftete zuerst), **Thema** `/forum/thema/{id}-{titel}` die Beiträge in Seiten. Globale Listen: `/forum/neu`, `/forum/aktiv`, `/forum/unbeantwortet`, Suche `/forum/suche`.
- **Blättern:** Foren- und Themenlisten verwenden einen Cursor (`?nach=…`, Keyset). Sie überspringen keine sich verändernden Seiten über SQL-`OFFSET`; lange Listen bleiben damit stabiler.
- **Neues Thema, Antworten, Zitieren, Bearbeiten:** Schaltflächen im Forum bzw. am Beitrag. Beiträge werden als Markdown gespeichert. Der lokal gebündelte **SunEditor** ist eine progressive Eingabehilfe; `md-bridge.js` wandelt nur den unterstützten HTML-Umfang in dieselbe Markdown-Quelle. Tabellen, eingebettete Bilder, Roh-HTML und andere nicht verlustfrei darstellbare Konstrukte bleiben in der Markdown-Ansicht. Fällt SunEditor aus, bleibt das Textfeld verwendbar. Bearbeiten ist im Bearbeitungsfenster (`edit_window_minutes` bzw. länger für vertrauenswürdige Mitglieder) möglich; frühere Fassungen bleiben erhalten.
- **Anhänge:** Datei wählen, ins Fenster ziehen oder einfügen (Bilder aus der Zwischenablage). Erlaubte Typen und Größe legt die Verwaltung fest. Anhänge liegen außerhalb des Webroots im privaten Speicher und werden unter `/forum/anhang/{id}` bzw. `/forum/anhang/{id}/vorschau` nur nach erneuter Leserechteprüfung ausgeliefert. Nicht abgeschickte Anhänge werden nach 24 Stunden gelöscht.
- **Entwürfe:** Ihr Text wird laufend im Browser gesichert; lokale Entwürfe verfallen nach 30 Tagen. Zusätzlich speichert `DraftService` für neue Themen je Forum und für Antworten je Thema einen Server-Entwurf (`/forum/api/entwurf`); nur Mitglieder mit Leserecht auf das Ziel dürfen ihn laden oder speichern. Ein Leerstand löscht den Entwurf, erfolgreiches Absenden oder „Verwerfen“ entfernt ihn; die Aufbewahrungsregel löscht alte Server-Entwürfe ebenfalls nach 30 Tagen.
- **Vorschau:** zeigt den Beitrag so, wie er veröffentlicht wird (gereinigt, ohne Skripte).
- **Captcha:** Für neue Themen und Antworten kann die Verwaltung eine Prüfung verlangen (`aus`, erster veröffentlichter Beitrag, Vertrauensstufe 0 oder immer); globale Moderation und das Bearbeiten sind ausgenommen. `CaptchaGate` nutzt nur lokale Verfahren des Cores: Proof-of-Work, ein mit GD erzeugtes Bild oder eine Rechenaufgabe. Bild und Proof-of-Work stellen eine Rechenaufgabe als Alternative bereit; Bild fällt ohne GD auf Rechenaufgabe zurück. Es gibt keine Datenübertragung an einen Captcha-Drittanbieter.

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

Gespeichert werden Beiträge, Reaktionen, Abos, Lesestatus, Entwürfe und – wenn konfiguriert – eine gekürzte, mit HMAC geschützte Kennung der IP-Adresse (nur für Spam-Abwehr/Moderation, Standard 30 Tage). Die Community speichert alle fachlichen Zeitwerte in UTC. Über die Datenschutz-Anfrage bzw. die Selbstauskunft erhalten Sie einen Export; der HMAC-Wert selbst ist nicht Teil des Exports, nur die Zahl betroffener Beiträge.

Bei der Kontolöschung gilt eine von zwei Einstellungen: `anonymize` (Standard) entfernt die Kontobezüge und erhält Inhalte als „Gelöschtes Mitglied“; `delete` leert zusätzlich Text und HTML-Cache eigener Beiträge, markiert `deleted_at`, löscht frühere Fassungen, löst Anhänge vom Beitrag, entfernt diese Beiträge aus der Community-Suche und lässt die Dateien durch die 24-Stunden-Regel für verwaiste Uploads aufräumen. Themen-Titel und unveränderte Zitate anderer Mitglieder bleiben. Die Datenschutzerklärung enthält die wirksamen Fristen und den Löschmodus im generierten Abschnitt **„8a. Forum (Community)“**.

### API und Suchmaschinen

Die Browseraktionen für Reaktionen, Abos, Meldungen, Vorschau, Moderation, Lösung, Upload, Verwerfen, Server-Entwurf und „Alles gelesen“ verwenden ausschließlich `POST /forum/api/*` (englisch entsprechend unter `/en/forum/api/*`). Die Ausgabe ist JSON mit CSRF-Prüfung; Rechte und Gründe bleiben in den Diensten, nicht im JavaScript. Öffentliche, veröffentlichte Themen der ersten Seite erhalten JSON-LD (`DiscussionForumPosting`; in Q&A-Foren `QAPage` mit `Question` und ggf. `Answer`). Interne oder nicht öffentliche Foren werden nicht so ausgezeichnet.

### Barrierefreiheit

Die Seiten sind mit Tastatur und Screenreader bedienbar: Sprunglink, benannte Navigationen, Beiträge als Artikel, Reaktionen mit Namen und Zähler, Dialoge mit Fokusführung. Probleme bitte der Moderation melden.
