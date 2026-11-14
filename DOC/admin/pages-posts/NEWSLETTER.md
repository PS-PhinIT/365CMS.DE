# 365CMS – Admin: Newsletter

> **Stand:** 2026-11-14 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

The newsletter (formerly the plugin cms-newsletter) is part of the core since 4.0.0: *Content → Newsletter* (`/admin/newsletter`, capability `manage_newsletter`). Subscribers sign up on `/newsletter` (also `/en/newsletter`) with double opt-in; confirming and unsubscribing require a POST so that link scanners cannot trigger them. Campaigns are sent in batches by cron (mail queue run and hourly), every mail has a footer with web and unsubscribe links and the RFC 8058 headers `List-Unsubscribe` / `List-Unsubscribe-Post`. GDPR export/deletion, a member-area section with a dashboard widget (subscribe, confirm resend, change topic/language, unsubscribe; consent history in the audit log) and a retention rule for unconfirmed signups are included. Data of the former plugin stays in its tables and is upgraded automatically.

## Deutsch

### Bereiche

| Bereich | Adresse | Inhalt |
|---|---|---|
| Übersicht | `/admin/newsletter` | Aktive und unbestätigte Abonnenten, Neuanmeldungen (30 Tage), versendete Ausgaben, letzte Kampagnen, Themen mit Abonnentenzahl, neueste Anmeldungen; Warnung ohne gültige Absenderadresse |
| Abonnenten | `?section=subscribers` | Suche, Filter Status/Thema, 50 je Seite, Sammelaktionen (aktivieren, abmelden, endgültig löschen), CSV-Export (`?export=csv`, mit Schutz vor Formel-Injection), Import, DSGVO-Auskünfte |
| Abonnent | `?section=subscriber[&id=…]` | E-Mail, Name, Status, Thema, Sprache; Einwilligungsnachweis (verknüpftes Benutzerkonto mit Link, Quelle, Zeitpunkte, Einwilligungstext), Einwilligungsverlauf aus dem Audit-Log, erhaltene Ausgaben, Bestätigungsmail erneut senden |
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
| Allgemein | Absendername, Absender- und Antwortadresse (leer = Adresse der Website), Double-Opt-In, Felder für Vor-/Nachname, Themenauswahl (gilt auch im Mitgliederbereich), Newsletter-Widget im Mitglieder-Dashboard (`member_widget_enabled`, Standard an), Frist für unbestätigte Anmeldungen, Themen (je Zeile `schlüssel\|Name\|Name Englisch`) und Standard-Thema |
| Inhalte | Titel, Beschreibung, Einleitung, Einwilligungstext und Hinweis unter dem Formular (DE/EN), Archiv auf der Anmeldeseite, Menüpunkt mit Text DE/EN |
| Versand | Mails je Durchlauf, Fußzeile der Mails DE/EN; Prüfung der Absenderdomain auf SPF, DMARC und BIMI (DKIM: Hinweis, braucht den Selektor des Mailanbieters) |

### Import und Export

- **Import:** CSV-Datei oder eingefügter Text, je Zeile `E-Mail;Vorname;Nachname;Thema` (Kopfzeile erlaubt, Trennzeichen `;` oder `,`, max. 5 MB). Vorhandene Adressen bleiben unverändert. Status wahlweise „Unbestätigt – Bestätigungsmail senden“ oder „Aktiv (Einwilligung liegt vor)“; das Vorliegen der Einwilligung muss bestätigt werden.
- **Export:** CSV mit E-Mail, Name, Status, Thema, Sprache, Quelle, den Zeitpunkten von Anmeldung, Einwilligung, Bestätigung und Abmeldung sowie der Spalte `user_id` (verknüpftes Benutzerkonto, leer ohne Konto).

### Datenschutz

- **Einwilligungsnachweis:** Zeitpunkt, Quelle und Wortlaut des Einwilligungstextes je Abonnent; IP-Adressen werden in der Abonnententabelle nicht gespeichert.
- **Einwilligungsverlauf:** Die Abonnententabelle hält nur die letzte Einwilligung. Jede Anmeldung, Änderung und Abmeldung im Mitgliederbereich sowie jede Bestätigung und Abmeldung über die Links der Mails landet zusätzlich im Audit-Log (Tabelle `audit_log`, angezeigt in der Abonnenten-Detailansicht unter „Einwilligungsverlauf“): Kategorie `newsletter`, Aktionen `newsletter.subscribe`, `newsletter.confirm`, `newsletter.unsubscribe`, `newsletter.preferences`, `newsletter.resend`, Entität `newsletter_subscriber` mit ID, Metadaten Quelle (`member`, `public`, `block` …), Status, Sprache, Thema, Einwilligungstext und -zeitpunkt (ohne E-Mail-Adresse; das Audit-Log selbst speichert wie bei allen Einträgen Konto, IP und User-Agent). Keine eigene Tabelle, keine Schemaänderung.
- **Token:** Das Empfänger-Token (`optin_token`) wird beim Bestätigen neu erzeugt. Bis dahin steckt es nur in Bestätigungsmails; Ausgaben gehen erst an aktive Abonnenten und enthalten schon das neue Token, ihre Abmelde- und Weblinks bleiben gültig. Ein zweiter Klick auf den Link einer Bestätigungsmail zeigt danach „Link ungültig oder abgelaufen“.
- **DSGVO-Auskunft:** Beim Abschließen einer Auskunftsanfrage (*Recht → DSGVO-Anfragen*) entsteht eine JSON-Datei (Abonnement, Einwilligung, erhaltene Ausgaben) in `private/newsletter/privacy-exports/`; Download unter Newsletter → Abonnenten, automatische Löschung nach 30 Tagen.
- **DSGVO-Löschung:** Löschanfragen entfernen das Abonnement samt Versandzeilen.
- **Selbstauskunft:** Der Datenexport im Mitgliederbereich enthält das Abonnement (Filter `cms_member_export_data`).
- **Löschfrist:** Regel „Newsletter: unbestätigte Anmeldungen“ (`newsletter.pending`) unter *Recht → Löschfristen*; Frist = Einstellung „Unbestätigte Anmeldungen löschen nach“ (Standard 14 Tage).

### Mitgliederbereich

Mitglieder verwalten das Abonnement ihrer Konto-Adresse direkt im **Dashboard-Widget** (`/member/dashboard#newsletter`) oder auf der Unterseite `/member/plugin/newsletter`. Beide nutzen dieselbe Vorlage und denselben Service (`NewsletterMemberService`).

| Status | Anzeige und Aktionen |
|---|---|
| kein Abo | Kurztext (Einleitung aus *Inhalte*), Konto-Adresse schreibgeschützt („Ändern im Profil“), Thema und Sprache (falls wählbar), Pflicht-Checkbox mit dem Einwilligungstext, „Newsletter abonnieren“ |
| Konto-Adresse unbestätigt | wie oben mit Hinweis: Anmeldung immer per Double-Opt-In; mit dem Konto verknüpft wird das Abo erst, wenn die Adresse über „Passwort vergessen“ bestätigt ist |
| unbestätigt (`pending`) | „Bestätigung ausstehend seit …“, „Bestätigungsmail erneut senden“ (höchstens alle 10 Minuten, sonst gesperrt mit Hinweis), „Anmeldung zurückziehen“ |
| aktiv | „bestätigt seit …“, Thema/Sprache ändern, „Abmelden“ mit Rückfrage |
| abgemeldet / unzustellbar | Status und „Wieder anmelden“ (erneut mit Einwilligung; bei unzustellbarer Adresse immer per Double-Opt-In) |

Regeln:
- Zuordnung über `user_id`, sonst nur über die **bestätigte** Konto-Adresse (`EmailVerificationService::matchableEmail()`); `user_id` wird nur bei bestätigter Adresse gesetzt. Für unbestätigte Konto-Adressen gilt dieselbe Meldung, egal ob die Adresse schon eingetragen ist.
- Double-Opt-In laut Einstellung, bei unbestätigter Konto-Adresse oder unzustellbarer Adresse immer.
- Ohne Haken bei der Einwilligung keine Anmeldung; gespeichert wird der Einwilligungstext in der Sprache der Oberfläche. Vor- und Nachname kommen aus dem Profil (`user_meta`).
- Themenauswahl nur mit „Themenauswahl anzeigen“ und mindestens zwei Themen; Sprachen: Deutsch plus die aktiven Inhaltssprachen, die der Newsletter versendet (Englisch).
- Höchstens 10 Aktionen je Stunde und Konto; jede Aktion landet im Audit-Log (siehe *Datenschutz*).
- Formulare posten an `/member/plugin/newsletter` mit dem Core-Token der Registry (ein Token je Seitenaufruf). Die Verarbeitung läuft vor jeder Ausgabe, die Rückmeldung kommt als Meldung (`role="status"` bzw. `role="alert"`) und die Seite wird per 303 neu geladen (Post/Redirect/Get): `return_to=dashboard` springt zu `/member/dashboard#newsletter`, sonst zur Unterseite.
- Einstellung „Newsletter-Widget im Mitglieder-Dashboard“ (*Allgemein*, Standard an): aus = nur die Kachel mit Link zur Unterseite. Den ganzen Bereich blendet die Sichtbarkeitsoption des Mitgliederbereichs aus (`member_dashboard_plugin_cms-newsletter`).
- Vorlage `views/member/widgets/newsletter.php`, im Theme überschreibbar als `themes/<theme>/member/widgets/newsletter.php`; Variablen: `$status`, `$csrfField`, `$actionUrl`, `$segments`, `$locales`, `$consentText`, `$notice`, `$returnTo` (Beschreibung im Kopf der Vorlage). Stylesheet `assets/css/member-newsletter.css` (nur Dashboard und Unterseite).

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
