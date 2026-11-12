# 365CMS – Admin: Newsletter

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

The newsletter (formerly the plugin cms-newsletter) is part of the core since 4.0.0: *Content → Newsletter* (`/admin/newsletter`, capability `manage_newsletter`). Subscribers sign up on `/newsletter` (also `/en/newsletter`) with double opt-in; confirming and unsubscribing require a POST so that link scanners cannot trigger them. Campaigns are sent in batches by cron (mail queue run and hourly), every mail has a footer with web and unsubscribe links and the RFC 8058 headers `List-Unsubscribe` / `List-Unsubscribe-Post`. GDPR export/deletion, a member-area section and a retention rule for unconfirmed signups are included. Data of the former plugin stays in its tables and is upgraded automatically.

## Deutsch

### Bereiche

| Bereich | Adresse | Inhalt |
|---|---|---|
| Übersicht | `/admin/newsletter` | Aktive und unbestätigte Abonnenten, Neuanmeldungen (30 Tage), versendete Ausgaben, letzte Kampagnen, Themen mit Abonnentenzahl, neueste Anmeldungen; Warnung ohne gültige Absenderadresse |
| Abonnenten | `?section=subscribers` | Suche, Filter Status/Thema, 50 je Seite, Sammelaktionen (aktivieren, abmelden, endgültig löschen), CSV-Export (`?export=csv`, mit Schutz vor Formel-Injection), Import, DSGVO-Auskünfte |
| Abonnent | `?section=subscriber[&id=…]` | E-Mail, Name, Status, Thema, Sprache; Einwilligungsnachweis (Quelle, Zeitpunkte, Einwilligungstext), erhaltene Ausgaben, Bestätigungsmail erneut senden |
| Kampagnen | `?section=campaigns[&status=…]` | Liste mit Status, Zielgruppe, Fortschritt |
| Kampagne | `?section=campaign[&id=…]` | Name, Vorlage, Betreff, Vorschautext, Inhalt (HTML und Nur-Text), Thema, Sprache, Archiv; Vorschau, Testversand, Jetzt senden, Planen, Stoppen, Fehlgeschlagene erneut senden, Kopieren, Löschen |
| Vorlagen | `?section=templates`, `?section=template[&id=…]` | Name, Betreff, Inhalt; eine Kampagne ohne eigenen Inhalt nutzt den Inhalt ihrer Vorlage |
| Einstellungen | `?section=settings&tab=general\|content\|delivery` | siehe unten |

### Status

- **Abonnenten:** Unbestätigt (`pending`), Aktiv, Abgemeldet, Unzustellbar (`bounced`).
- **Kampagnen:** Entwurf, Geplant, Wird versendet, Versendet, Gestoppt. Laufende und versendete Ausgaben sind gesperrt; nur die Sichtbarkeit im Archiv lässt sich noch ändern.

### Versand

„Jetzt senden“ legt für jeden passenden aktiven Abonnenten (Thema, Sprache) eine Versandzeile an und verschickt sofort den ersten Block. Den Rest übernimmt der Cron: bei jedem Lauf der Mail-Warteschlange und stündlich höchstens „Mails je Durchlauf“ (Standard 50). Ist die Mail-Warteschlange aktiv, landen die Mails dort und werden zusätzlich von ihr gedrosselt. Geplante Kampagnen startet derselbe Lauf zum eingestellten Zeitpunkt. Wer sich während des Versands abmeldet, erhält keine Mail mehr. Die Kampagnenseite lädt sich während des Versands alle 20 Sekunden neu, solange nichts bearbeitet wird.

Jede Mail enthält:
- Personalisierung über Platzhalter: `{{first_name}}` (ohne Vorname „zusammen“/„there“), `{{last_name}}`, `{{email}}`, `{{site_name}}`, `{{site_url}}`, `{{date}}`, `{{unsubscribe_url}}`, `{{web_url}}`.
- Fußzeile mit der Einstellung „Fußzeile der Mails“ (z. B. Anbieterkennzeichnung) sowie den Links „Im Browser ansehen“ und „Abmelden“.
- Die Kopfzeilen `List-Unsubscribe` (Link und `mailto:`) und `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058), die z. B. Gmail und Yahoo für Massenversender verlangen.

Inhalte bereinigt der Core-Purifier (Profil `default`): keine Skripte, keine Inline-Stile. Die Gestaltung übernimmt das Mail-Layout des Cores.

### Öffentliche Seiten

| Adresse | Zweck |
|---|---|
| `/newsletter` | Anmeldung (Name optional, Themenauswahl optional, Einwilligung Pflicht, Link zur Datenschutzerklärung aus *Einstellungen → Formulare*) und Archiv versendeter, als öffentlich markierter Ausgaben |
| `POST /newsletter/subscribe` | Anmeldung: CSRF-Token, Honeypot, max. 6 Versuche je Stunde und IP. Die Rückmeldung ist für neue, bestehende und abgemeldete Adressen gleich; eine erneute Bestätigungsmail geht höchstens alle 10 Minuten hinaus |
| `/newsletter/confirm/<token>` | Seite mit Knopf „Anmeldung bestätigen“, erst der POST bestätigt |
| `/newsletter/unsubscribe/<token>` | Seite mit Knopf „Abmelden“; der POST meldet ab – auch als One-Click-Abmeldung des Postfachanbieters (Antwort `200 Unsubscribed`) |
| `/newsletter/issue/<id>` | Webversion: öffentlich bei Archiv-Ausgaben, sonst nur mit Empfänger-Token (`?t=…`, `noindex`) |

Alle Seiten gibt es auch unter `/en/…`. Vorlagen `views/newsletter/{page,action,issue}.php`, im Theme überschreibbar unter `themes/<theme>/newsletter/<vorlage>.php`. Optionaler Menüpunkt in der Hauptnavigation (Einstellungen → Inhalte).

Bestätigen und Abmelden passieren bewusst erst per POST: Mailfilter wie Microsoft Safe Links rufen Links vorab auf und hätten sonst Anmeldungen bestätigt bzw. Abonnenten abgemeldet. Die Pfade unter `/newsletter/` sind vom globalen CSRF-Schutz öffentlicher POSTs ausgenommen; die Anmeldung prüft ihr eigenes Token, Bestätigen und Abmelden sind über das geheime Empfänger-Token geschützt.

### Einstellungen

| Tab | Inhalt |
|---|---|
| Allgemein | Absendername, Absender- und Antwortadresse (leer = Adresse der Website), Double-Opt-In, Felder für Vor-/Nachname, Themenauswahl, Frist für unbestätigte Anmeldungen, Themen (je Zeile `schlüssel\|Name\|Name Englisch`) und Standard-Thema |
| Inhalte | Titel, Beschreibung, Einleitung, Einwilligungstext und Hinweis unter dem Formular (DE/EN), Archiv auf der Anmeldeseite, Menüpunkt mit Text DE/EN |
| Versand | Mails je Durchlauf, Fußzeile der Mails DE/EN; Prüfung der Absenderdomain auf SPF, DMARC und BIMI (DKIM: Hinweis, braucht den Selektor des Mailanbieters) |

### Import und Export

- **Import:** CSV-Datei oder eingefügter Text, je Zeile `E-Mail;Vorname;Nachname;Thema` (Kopfzeile erlaubt, Trennzeichen `;` oder `,`, max. 5 MB). Vorhandene Adressen bleiben unverändert. Status wahlweise „Unbestätigt – Bestätigungsmail senden“ oder „Aktiv (Einwilligung liegt vor)“; das Vorliegen der Einwilligung muss bestätigt werden.
- **Export:** CSV mit E-Mail, Name, Status, Thema, Sprache, Quelle und den Zeitpunkten von Anmeldung, Einwilligung, Bestätigung und Abmeldung.

### Datenschutz

- **Einwilligungsnachweis:** Zeitpunkt, Quelle und Wortlaut des Einwilligungstextes je Abonnent; IP-Adressen werden nicht gespeichert.
- **DSGVO-Auskunft:** Beim Abschließen einer Auskunftsanfrage (*Recht → DSGVO-Anfragen*) entsteht eine JSON-Datei (Abonnement, Einwilligung, erhaltene Ausgaben) in `private/newsletter/privacy-exports/`; Download unter Newsletter → Abonnenten, automatische Löschung nach 30 Tagen.
- **DSGVO-Löschung:** Löschanfragen entfernen das Abonnement samt Versandzeilen.
- **Selbstauskunft:** Der Datenexport im Mitgliederbereich enthält das Abonnement (Filter `cms_member_export_data`).
- **Löschfrist:** Regel „Newsletter: unbestätigte Anmeldungen“ (`newsletter.pending`) unter *Recht → Löschfristen*; Frist = Einstellung „Unbestätigte Anmeldungen löschen nach“ (Standard 14 Tage).

### Mitgliederbereich

`/member/plugin/newsletter`: Abonnement mit der Konto-Adresse an- und abmelden, Thema und Sprache wählen. Mit Double-Opt-In kommt auch hier eine Bestätigungsmail.

### Hooks

| Hook | Typ | Argumente |
|---|---|---|
| `cms_newsletter_subscribed` | Action | `int $subscriberId` – Abonnent wurde aktiv |
| `cms_newsletter_unsubscribed` | Action | `int $subscriberId` |
| `cms_newsletter_subscriber_deleted` | Action | `int $subscriberId` |
| `cms_newsletter_campaign_started` | Action | `int $campaignId` |
| `cms_newsletter_campaign_sent` | Action | `int $campaignId` |
| `cms_member_export_data` | Filter | `array $export`, `int $userId`, `string $email` – Selbstauskunft ergänzen |

### Übernahme aus dem Plugin

Die Tabellen `newsletter_*` des Plugins werden weiterverwendet. `NewsletterPluginMigrator` ergänzt neue Spalten und Schlüssel, setzt den Plugin-Status „ready“ auf Entwurf, trägt Einwilligungs- und Abmeldezeitpunkte nach und legt fehlende Standardwerte an; Platzhalter-Absender `…@example.com` werden geleert. Einstellungen bleiben erhalten; ein zweiter Lauf ändert nichts. Alte Adressen `/admin/plugins/newsletter-dashboard/…` leiten auf die passenden Bereiche um.

Im Plugin fehlten der Versand selbst (Kampagnen ließen sich nur als „gesendet“ markieren), die Bestätigungsmail und die Route zum Bestätigen – unbestätigte Abonnenten konnten nie aktiv werden. Ein GET auf den Abmeldelink meldete sofort ab.

### Rechte

`manage_newsletter` (Rollen-Gruppe „Newsletter“).
