# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Bereich 5: Zahlungen, Abos & Rechnungen
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1.0 · Schema v26 | **Status:** Spezifikation | **Übersicht:** [README.md](README.md)

## English (summary)

Payment gateways (Stripe first, then Mollie which also covers PayPal; PayPal direct optional) for `orders.php` and
`/member/subscription`: hosted checkout (no card data on the server), signed webhooks as the single source of truth,
idempotent event processing, automatic activation and renewal of subscriptions, and legally compliant PDF invoices
with gap-free sequential numbering, rendered with the existing Dompdf-based `PdfService` and stored in private
storage. Lightweight REST clients over `Http\Client` instead of vendor SDKs.

## Deutsch

### 1. Bestand

Tabelle `orders` (u. a. `order_number`, `user_id`, `plan_id`, Beträge, `status`, `payment_method`, `payment_ref`),
`user_subscriptions`, `subscription_plans`, `SubscriptionManager`; Abo-Einstellungen kennen die Zahlarten `invoice`,
`stripe`, `paypal`, `all` – ohne Gateway-Anbindung. `PdfService` (Dompdf) vorhanden.

### 2. Grundsätze

- **Keine Kartendaten auf dem Server** (PCI-DSS SAQ A): Stripe Checkout bzw. Mollie Hosted Payment Page; das CMS kennt nur IDs.
- **Webhooks sind die Wahrheit:** Der Rücksprung des Browsers zeigt nur „wird bestätigt“; aktiviert wird ausschließlich durch verifizierte Webhook-Ereignisse (bzw. Abfrage der Zahlung beim Anbieter).
- **Idempotenz:** jedes Anbieter-Ereignis einmal verarbeitet (`payment_events.provider_event_id` eindeutig).
- **Schlanke Clients** über `Http\Client` (nur benötigte Endpunkte) statt `stripe/stripe-php` bzw. PayPal-SDK; Geheimnisse verschlüsselt.

### 3. Technik

| Baustein | Aufgabe |
|---|---|
| `CMS\Services\Payments\PaymentGatewayInterface` | `createCheckout(Order)`, `verifyWebhook(headers, rawBody)`, `fetchPayment(id)`, `createRefund()` (optional, P2) |
| `StripeGateway` | Checkout Session (`mode=payment` bzw. `subscription`), Webhook-Prüfung `Stripe-Signature` (HMAC-SHA256 über `t.payload`, Toleranz 300 s, mehrere `v1`-Signaturen) |
| `MollieGateway` | Payments API / Subscriptions API; Webhook enthält nur die ID → Zahlung beim Anbieter abrufen (Mollie signiert nicht; Authentizität über Abruf mit API-Key) |
| `PayPalGateway` (optional) | Orders v2; Webhook-Prüfung über `verify-webhook-signature`-Endpunkt |
| `PaymentService` | Bestellung → Checkout, Webhook → Zustandswechsel, Abo-Aktivierung/-Verlängerung über `SubscriptionManager`, Ereignisse `cms_payment_succeeded`/`_failed`, `cms_subscription_renewed` |
| `InvoiceService` | Rechnung bei Zahlungseingang: Nummernkreis, PDF über `PdfService`, Ablage `PrivateStorageService` (`invoices/{yyyy}/`), Mail mit PDF (Mail-Warteschlange), Hook `cms_invoice_created` |
| Routen | `POST /payments/webhook/{provider}` (keine Sitzung, kein CSRF, nur Signatur), `GET /orders/return/{order}` (signierter Rücksprung), `/member/subscription/invoices`, `GET /member/invoices/{id}` (Download mit Eigentumsprüfung) |
| Admin | *Abos → Zahlungen* (Anbieter, Testmodus, Schlüssel, Webhook-Geheimnis, Webhook-URL zum Kopieren), *Rechnungen* (Liste, Download, Storno-Rechnung erstellen), Capabilities `payments.manage`, `invoices.view` |

### 4. Rechnungen (rechtssicher, DE)

- **Fortlaufende, lückenlose Nummer** je Nummernkreis (Format einstellbar, z. B. `RE-{YYYY}-{NNNNN}`), vergeben in einer Transaktion mit Sperre auf den Zähler (`SELECT … FOR UPDATE`), nie wiederverwendet.
- Pflichtangaben (§ 14 UStG): Name/Anschrift Leistender und Empfänger, Steuernummer oder USt-IdNr., Ausstellungsdatum, Rechnungsnummer, Menge/Art der Leistung, Leistungszeitraum, Entgelt nach Steuersätzen, Steuerbetrag, Hinweis bei Steuerbefreiung/Kleinunternehmer (§ 19 UStG) bzw. Reverse Charge.
- **Unveränderlich:** PDF und Rechnungsdaten werden beim Erstellen festgeschrieben (Snapshot), SHA-256 gespeichert; Korrektur nur über Storno-Rechnung (eigene Nummer, Verweis).
- Aufbewahrung 10 Jahre (keine Löschung über DSGVO-Löschantrag; Hinweis im Datenschutztext; Sperrvermerk statt Löschung).
- Optional später: E-Rechnung (ZUGFeRD/XRechnung) – nicht in 4.1.0.

### 5. Datenmodell (v26)

```sql
ALTER TABLE {p}orders ADD COLUMN provider VARCHAR(20) NULL, ADD COLUMN provider_checkout_id VARCHAR(255) NULL,
  ADD COLUMN paid_at DATETIME NULL, ADD INDEX idx_provider_checkout (provider, provider_checkout_id);
ALTER TABLE {p}user_subscriptions ADD COLUMN provider VARCHAR(20) NULL,
  ADD COLUMN provider_subscription_id VARCHAR(255) NULL, ADD COLUMN current_period_end DATETIME NULL,
  ADD INDEX idx_provider_sub (provider, provider_subscription_id);
CREATE TABLE {p}payment_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(20) NOT NULL, provider_event_id VARCHAR(255) NOT NULL,
  type VARCHAR(80) NOT NULL, order_id BIGINT UNSIGNED NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'received',     -- received | processed | ignored | failed
  payload_hash CHAR(64) NOT NULL, error VARCHAR(500) NULL,
  received_at DATETIME NOT NULL, processed_at DATETIME NULL,
  UNIQUE KEY uq_event (provider, provider_event_id)
);
CREATE TABLE {p}invoice_sequences (
  sequence_key VARCHAR(30) PRIMARY KEY, next_number INT UNSIGNED NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE {p}invoices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_number VARCHAR(40) NOT NULL, sequence_key VARCHAR(30) NOT NULL,
  type VARCHAR(12) NOT NULL DEFAULT 'invoice',        -- invoice | cancellation
  order_id BIGINT UNSIGNED NULL, user_id INT UNSIGNED NULL, cancels_invoice_id BIGINT UNSIGNED NULL,
  issued_at DATETIME NOT NULL, service_period_start DATE NULL, service_period_end DATE NULL,
  currency CHAR(3) NOT NULL, net_total DECIMAL(12,2) NOT NULL, tax_total DECIMAL(12,2) NOT NULL, gross_total DECIMAL(12,2) NOT NULL,
  snapshot_json LONGTEXT NOT NULL, pdf_path VARCHAR(255) NOT NULL, pdf_sha256 CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_number (invoice_number), KEY idx_user (user_id, issued_at), KEY idx_order (order_id)
);
```

### 6. Sicherheits-Gates

- [ ] Webhook-Signatur vor jeder Verarbeitung; konstante Zeitvergleiche; Zeittoleranz; Rohkörper vor JSON-Parsing signiert.
- [ ] Beträge/Währung aus der eigenen Bestellung, nie aus Client-Parametern; Abgleich mit dem Betrag im Anbieter-Ereignis.
- [ ] Idempotente Verarbeitung; Zustandsautomat der Bestellung (`pending → paid → refunded`, keine Rücksprünge).
- [ ] Rechnungs-Download nur für Eigentümer bzw. `invoices.view`; PDFs außerhalb des Webroots.
- [ ] Geheimnisse verschlüsselt, Testmodus deutlich markiert, getrennte Schlüssel für Test/Live.
- [ ] Webhook-Route mit Rate-Limit und Größenlimit (64 KB).

### 7. Tests (`TESTS/payments/run.php`)

Stripe-Signatur (gültig, falsch, alt, mehrere `v1`), Mollie-Abrufpfad mit Fake-Client, Idempotenz (gleiches Ereignis zweimal),
Betragsabweichung → `failed`, Abo-Verlängerung setzt `current_period_end`, Nummernkreis lückenlos unter Parallelität
(zwei Transaktionen), Storno-Rechnung, PDF-Pflichtangaben vorhanden (Text-Extraktion), Download-Rechte.
