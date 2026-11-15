# 365CMS – Projektdokumentation | Abschnitt: Admin – Rechnungen

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** in Entwicklung (Bereich 5b)
> **Route:** `/admin/invoices` | **Capability:** `invoices.view` | **CSRF-Aktion:** `admin_invoices`

## English (summary)

Legally compliant invoices for German VAT law (§ 14 UStG). An invoice is created automatically when a payment arrives (`cms_payment_succeeded`) or a subscription is renewed (`cms_subscription_renewed`), and manually in the admin for any paid order (e.g. purchase on account). Numbers come from a gap-free sequence per format (default `RE-{YYYY}-{NNNNN}`, one counter per year), allocated in one database transaction with a lock on `invoice_sequences` (`SELECT … FOR UPDATE`) together with the PDF, the file and the invoice row; if any step fails everything is rolled back, so no number is lost, and numbers are never reused. Invoice data is frozen as a JSON snapshot, the PDF (Dompdf via `PdfService`) is stored in private storage under `invoices/{yyyy}/` outside the web root and its SHA-256 is checked on every download. Corrections only by a cancellation invoice with its own number and a reference. Mandatory fields are checked before a number is allocated (incl. small-business note § 19 UStG, reverse charge, small-amount invoices ≤ 250 €). The PDF is mailed through the mail queue. Members see their invoices at `/member/subscription/invoices` and download them at `/member/invoices/{id}` (owner only). Invoices are kept for 10 years: GDPR deletions only set a restriction flag.

## Deutsch

### Wann entsteht eine Rechnung?

| Anlass | Auslöser | Leistungszeitraum |
|---|---|---|
| Zahlungseingang einer Erstbestellung | Hook `cms_payment_succeeded(int $orderId, string $provider, array $event)` aus Bereich 5a | ab Zahlungsdatum (`paid_at`), ein Abrechnungszeitraum (`billing_cycle`; einmalig = Leistungsdatum) |
| Abo-Verlängerung | Hook `cms_subscription_renewed(int $subscriptionRowId, int $userId, int $renewalOrderId, int $periodEndTimestamp)` – eigene bezahlte Zeile in `orders` | Periodenende minus Abrechnungszeitraum bis Periodenende |
| Manuell (z. B. Rechnungskauf) | *Abos → Rechnungen → Rechnung für Bestellung erstellen* | wie Erstbestellung |

- Nur für **bezahlte** Bestellungen (`paid`, `confirmed`, `completed`).
- **Idempotent:** Hat eine Bestellung schon eine nicht stornierte Rechnung, entsteht keine zweite – auch nicht bei doppelt zugestellten Zahlungsereignissen oder zwei gleichzeitigen Anfragen (Prüfung nach der Sperre des Nummernkreises).
- Beträge stammen aus der eigenen Bestellung (`amount` = netto, `tax_amount`, `total_amount`, `currency`), nie aus dem Zahlungsereignis. Netto + Steuer ≠ Brutto → keine Rechnung.
- Schalter *Automatik* in den Einstellungen: aus = nur manuell.

### Nummernkreis

- **Format** je Rechnungsart (Einstellung): Platzhalter `{YYYY}`, `{YY}`, `{MM}` und genau ein Zähler `{NNN}` … `{NNNNNNNNNN}` (Stellen = Mindestlänge, der Zähler wird nie abgeschnitten). Erlaubt sind außerdem Buchstaben, Ziffern und `- _ / .`; höchstens 40 Zeichen.
- **Nummernkreis** = Format mit aufgelösten Datumsplatzhaltern (`RE-2026-#`). Mit `{YYYY}` beginnt jedes Jahr neu, ohne Datumsplatzhalter läuft die Nummer über die Jahre durch.
- **Startnummer** gilt nur für *neue* Kreise (z. B. Übernahme aus einem Altsystem). Ein laufender Kreis zählt nie zurück.
- **Storno-Rechnungen** haben ein eigenes Format (Standard `ST-{YYYY}-{NNNNN}`). Gleiches Format wie Rechnungen = gemeinsamer Kreis.
- **Lückenlos:** Sperre auf die Zeile in `invoice_sequences` (`SELECT … FOR UPDATE`), dann Pflichtangaben, PDF, Datei, Rechnungszeile und Zählererhöhung (mit Vergleich des alten Werts) in **einer** Transaktion. Scheitert ein Schritt (z. B. Dompdf), wird alles zurückgerollt und die Datei entfernt; die Nummer bleibt frei. Läuft der Aufruf bereits in einer Transaktion (z. B. im Zahlungsablauf), nutzt der Dienst einen Savepoint.
- **Nie wiederverwendet:** Der Zähler sinkt nie; `UNIQUE (invoice_number)` und `UNIQUE (sequence_key, sequence_number)` sichern zusätzlich in der Datenbank ab. Die Vorschau *Nächste Nummer* vergibt nichts.

### Pflichtangaben (§ 14 Abs. 4 UStG)

Vor der Vergabe prüft `InvoiceService::missingMandatoryFields()`; fehlt etwas, entsteht keine Rechnung (Admin-Meldung bzw. Protokoll `invoices`) und es wird **keine Nummer verbraucht**.

| Angabe | Quelle |
|---|---|
| Name und Anschrift des Leistenden | Einstellungen *Absender*, sonst Rechtstexte-Profil (`legal_profile_company_name` + Rechtsform bzw. Inhaber, Straße, PLZ, Ort, Land) |
| Steuernummer **oder** USt-IdNr. des Leistenden | Einstellung *Steuernummer* bzw. *USt-IdNr.* (sonst `legal_profile_vat_id`) |
| Name und Anschrift des Empfängers | Bestellung (`company`, `forename`/`lastname`, `street`, `zip`, `city`, `country`) |
| Ausstellungsdatum, Rechnungsnummer | Vergabe |
| Menge und Art der Leistung | „Abonnement „Paket“ – monatlich/jährlich/einmalig“, Menge 1 |
| Leistungszeitpunkt/-zeitraum | siehe oben |
| Entgelt nach Steuersätzen, Steuersatz, Steuerbetrag | Bestellung |
| Hinweis bei Steuerbefreiung | *Kleinunternehmer:* fester Text „Gemäß § 19 UStG wird keine Umsatzsteuer berechnet …“; *Reverse Charge:* „Steuerschuldnerschaft des Leistungsempfängers …“ + USt-IdNr. des Empfängers (Pflicht); *Regelbesteuerung mit 0 %:* Einstellung *Hinweis bei 0 %* (Pflicht) |

- **Steuermodus** (Einstellung): Regelbesteuerung, Kleinunternehmer (§ 19 UStG: Brutto = Entgelt, keine Steuer ausgewiesen) oder Reverse Charge. Reverse Charge wird außerdem automatisch gewählt, wenn die Bestellung keine Umsatzsteuer enthält und der Kunde im EU-Ausland mit USt-IdNr. sitzt (USt-IdNr. aus `contact_data` `vat_id`/`ust_id`, im Admin-Formular auch direkt). Reverse Charge mit berechneter Steuer wird abgelehnt.
- **Kleinbetragsrechnung** (§ 33 UStDV): bis 250 € brutto ohne vollständige Empfängeranschrift erlaubt (dann auch ohne Steuernummer); das PDF trägt den Hinweis. Darüber ist die Anschrift Pflicht.
- Im Fuß zusätzlich Kontakt, Geschäftsführung, Registergericht/-nummer (Rechtstexte-Profil), Bankverbindung und eine optionale Fußzeile.

### Unveränderlich, Ablage, Download

- Beim Erstellen werden alle Angaben als **Snapshot** (`snapshot_json`, Version 1) und als PDF festgeschrieben; spätere Änderungen an Bestellung, Paket oder Absender wirken nicht zurück.
- PDF: `PdfService::generateFromHtml()` (Dompdf, ohne Remote-Zugriff und ohne Skripte), Schrift DejaVu Sans. Ablage `PrivateStorageService` → `invoices/{yyyy}/<Nummer>-<zufällig>.pdf` (gesperrt per `.htaccess`, empfohlen außerhalb des Webroots über `CMS_PRIVATE_PATH`). Dateien werden exklusiv angelegt, nie überschrieben.
- **SHA-256** in `pdf_sha256`; jeder Download vergleicht die Datei – verändert → kein Download, Fehler im Protokoll `invoices`.
- **Korrektur nur per Storno-Rechnung:** *Stornieren …* in der Liste (Grund erscheint auf dem PDF). Die Storno-Rechnung hat eine eigene Nummer, verweist auf Nummer und Datum der Rechnung (`cancels_invoice_id`, Text im PDF), trägt die negativen Beträge und gehört demselben Konto. Je Rechnung genau ein Storno, keine Storno-Rechnung einer Storno-Rechnung. Danach kann für die Bestellung eine neue Rechnung erstellt werden.

### Rechte

| Wer | Was |
|---|---|
| Mitglied | `/member/subscription/invoices` (auch `/member/invoices`): eigene Rechnungen; Download `GET /member/invoices/{id}` nur für den Eigentümer (`user_id` der Bestellung), nicht bei Sperrvermerk. Fremde oder unbekannte IDs → 404. |
| Admin mit `invoices.view` | `/admin/invoices`: alle Rechnungen inkl. gesperrter, Download `?download=<id>`, Rechnung erstellen, Storno, Mail erneut senden, Einstellungen |

Jedes POST-Formular trägt das CSRF-Token `admin_invoices`; keine Inline-Skripte (CSP). Erstellen, Storno und Einstellungen landen im Audit-Protokoll (`invoices.*`).

### Mail

Nach dem Erstellen geht eine Mail mit dem PDF als Anhang an die E-Mail der Bestellung – über die Mail-Warteschlange (`MailService::queueWithAttachment()`), bei abgeschalteter Warteschlange direkt. Abschaltbar in den Einstellungen und je manueller Aktion; *Mail* in der Liste sendet erneut.

### Aufbewahrung und Datenschutz

- Rechnungen werden **nie gelöscht** – weder vom Dienst noch durch die Datenaufbewahrung (Regel `invoices.archive`, Frist 0 = keine automatische Löschung; Aufbewahrung 10 Jahre ab Ende des Ausstellungsjahres, § 147 AO, § 14b UStG).
- **DSGVO-Löschantrag** (`dsgvo_delete_data`) und **Löschen eines Kontos** (`cms_user_deleted`): Rechnungen und PDFs bleiben, erhalten aber einen **Sperrvermerk** (`restricted_at`, Art. 18 i. V. m. Art. 17 Abs. 3 lit. b DSGVO). Gesperrte Rechnungen erscheinen nicht mehr im Mitgliederbereich, nur noch für Admins mit `invoices.view` (Kennzeichnung *Gesperrt*). Die Tabellen haben keinen Fremdschlüssel auf `users`, nichts wird kaskadierend gelöscht.
- **DSGVO-Auskunft** (`dsgvo_export_data`, *Rechtliches → Auskunft & Löschen* → abschließen): alle Rechnungen der Person, auch gesperrte, zugeordnet über das Konto oder die E-Mail der Bestellung, als JSON mit Rechnungsdaten (`InvoicePrivacy`, privater Speicher `invoices/privacy-exports/`, Download unter *Abos → Rechnungen*, Löschung nach 30 Tagen). Die Selbstauskunft im Mitgliederbereich enthält den Schlüssel `invoices`.
- **Datenschutzerklärung:** Die Vorlage unter *Rechtstexte* nennt im Abschnitt „Vertrags- und Zahlungsabwicklung“ die zehnjährige Aufbewahrung (§ 147 AO, § 14b UStG, § 257 HGB) und die Sperrung statt Löschung; bestehende Texte neu erzeugen.
- **Webhook** `invoice.created` (`invoice_id`, `type`, `order_id`) für ausgehende Webhooks (*Einstellungen → Webhooks*).

### Einstellungen (`invoices.*`)

`number_format`, `cancellation_format`, `start_number`, `tax_mode` (`standard` | `small_business` | `reverse_charge`), `exempt_note`, `auto_create`, `send_mail`, Absender `sender_name`, `sender_street`, `sender_zip`, `sender_city`, `sender_country`, `sender_email`, `tax_number`, `vat_id`, `bank_name`, `iban`, `bic`, `footer_note`. Leere Absenderfelder übernehmen das Rechtstexte-Profil (*Recht & Datenschutz → Rechtstexte*); die Seite zeigt je Feld den wirksamen Wert und warnt bei fehlenden Pflichtangaben.

> Die älteren Felder *Rechnungspräfix* und *Nächste Rechnungsnummer* unter `/admin/packages` werden von `InvoiceService` nicht verwendet.

### Technik

- `CMS\Services\Payments\InvoiceService` – Nummernkreis, Snapshot, PDF, Ablage, Storno, Rechte, Einstellungen; Datenbank, Uhr, Ablage, PDF-Erzeuger, Mailversand und Einstellungen per Konstruktor ersetzbar. `InvoiceService::schemaQueries()` liefert die CREATE-Statements.
- `CMS\Services\FeatureHooks\InvoiceHooks` – Hooks aus Bereich 5a, Routen, Menüpunkt, Sperrvermerk, Aufbewahrungsregel.
- Tabellen `cms_invoice_sequences` (`sequence_key`, `next_number`, `updated_at`) und `cms_invoices` (Nummer, Kreis, laufende Nummer, Typ, Bestellung, Konto, Storno-Verweis, Datum, Leistungszeitraum, Beträge, Steuermodus, Snapshot, PDF-Pfad, SHA-256, Ersteller, Sperrvermerk).
- Hook `cms_invoice_created(int $invoiceId, string $type, int $orderId, int $userId)` nach dem Erstellen (`type` = `invoice` | `cancellation`).
- Seiten: `CMS/admin/invoices.php` → `CMS/admin/views/subscriptions/invoices.php`, `CMS/member/invoices.php`.
- Tests: `php TESTS/invoices/run.php [-v]`.
- Nicht in 4.1.0: E-Rechnung (ZUGFeRD/XRechnung).

### Verwandte Dokumente

[ORDERS.md](ORDERS.md) · [SUBSCRIPTION-SYSTEM.md](SUBSCRIPTION-SYSTEM.md) · [../legal/LEGAL.md](../legal/LEGAL.md) · [../legal/DATA-RETENTION.md](../legal/DATA-RETENTION.md) · [../../member/MEMBER-ROUTES.md](../../member/MEMBER-ROUTES.md) · [../../core/HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md)
