# 365CMS – Projektdokumentation | Abschnitt: Admin – Zahlungen

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** veröffentlicht
> **Route:** `/admin/payments` | **Capability:** `payments.manage` | **CSRF-Aktion:** `admin_payments`

## English (summary)

Online payments for the checkout (`orders.php`) through **Stripe** (Checkout Session) and **Mollie** (hosted payment page; PayPal runs through Mollie). No card data reaches the server (PCI-DSS SAQ A). Orders become *paid* only through a verified provider webhook (`POST /payments/webhook/{stripe|mollie}`): Stripe signatures are checked (HMAC-SHA256 over `t.payload`, 300 s tolerance, several `v1`), Mollie payments are fetched from the API with the own key. Every provider event is processed once (`cms_payment_events`, unique per provider and event id). Amount and currency always come from the own order; a mismatch leaves the order unpaid. Order states: pending → paid | failed | cancelled, failed → paid | cancelled, paid → refunded. Paid orders of logged-in customers activate the plan; with *automatic renewal* Stripe/Mollie subscriptions are created and renewals extend `current_period_end` and add a paid follow-up order. Keys are stored encrypted, separately for test and live. Service: `CMS\Services\Payments\PaymentService`.

## Deutsch

### Einrichten

1. *Abos → Zahlungen* öffnen, **Testmodus** eingeschaltet lassen.
2. **Stripe:** Secret Key (`sk_test_…` bzw. eingeschränkter Schlüssel `rk_test_…`) eintragen. Im Stripe-Dashboard unter *Entwickler → Webhooks* die angezeigte Webhook-URL mit den aufgeführten Ereignissen anlegen und das Signatur-Geheimnis (`whsec_…`) übernehmen.
3. **Mollie:** API-Key (`test_…`) eintragen. Die Webhook-URL wird bei jeder Zahlung mitgegeben.
4. Unter *Abos → Pakete* bei „Zahlungsmethoden“ Stripe, Mollie, PayPal (über Mollie) oder „Alle“ wählen. Nicht eingerichtete Anbieter blendet der Checkout aus; „Rechnung“ bleibt immer möglich.
5. Testkauf durchführen, unter „Letzte Anbieter-Ereignisse“ prüfen, dann Live-Schlüssel eintragen und den Testmodus ausschalten.

Leere Schlüsselfelder behalten den gespeicherten Wert. Formate werden geprüft, damit Test- und Live-Schlüssel nicht vertauscht werden. Ein eingeschalteter Anbieter ohne vollständige Schlüssel im aktuellen Modus wird nicht gespeichert.

### Ablauf

| Schritt | Was passiert |
|---|---|
| Kunde bestellt mit Online-Zahlart | Bestellung `pending`, Checkout beim Anbieter (Betrag aus der Bestellung), Weiterleitung |
| Rücksprung `/payments/return/{Bestellnummer}?sig=…` | Signierter Link; zeigt nur den Stand („wird bestätigt“ aktualisiert sich alle 5 s) |
| Webhook des Anbieters | Prüfen, einmalig aufnehmen, Bestellung `paid` (Zeit `paid_at`), Abo zuweisen, Ereignis `cms_payment_succeeded` |
| Abo-Verlängerung | Periodenende verschieben, bezahlte Folgebestellung (`…-V…`), Ereignis `cms_subscription_renewed` |
| Zahlung fehlgeschlagen / abgebrochen | `failed` bzw. `cancelled`, Ereignis `cms_payment_failed` |
| Abo beim Anbieter beendet | `user_subscriptions.status = cancelled`, Ereignis `cms_subscription_ended` |

Gastbestellungen (ohne Konto) werden bezahlt, aber keinem Konto zugewiesen. Automatische Verlängerung gibt es nur für angemeldete Kunden.

### Sicherheit

- Webhook-Route ohne Sitzung und CSRF, aber mit Signatur bzw. Abruf beim Anbieter, Rate-Limit (300/min je IP), Körper höchstens 64 KB; auch im Wartungsmodus erreichbar.
- **Verifizierung:** Stripe über die Signatur `Stripe-Signature` (HMAC-SHA256, 300 s Zeitfenster, mehrere `v1`-Werte erlaubt); Mollie liefert nur eine Zahlungs-ID, deren Stand das CMS selbst beim Anbieter abruft.
- **Idempotenz:** Jedes Ereignis wird genau einmal verarbeitet – `cms_payment_events` ist eindeutig je (`provider`, `provider_event_id`); wiederholte Zustellungen ändern nichts. Der Zustandsautomat der Bestellung kennt keine Rücksprünge (`paid` wird nie wieder `pending`).
- Beträge und Währung nie aus dem Request; Abweichung → Ereignis `failed`, Bestellung bleibt offen.
- Schlüssel verschlüsselt (`SettingsService`), getrennt für Test und Live; die Seite zeigt nur „gesetzt“.
- Webhooks nach außen (`order.paid`, `order.failed`, `subscription.renewed`) enthalten nur IDs.

### Grenzen (4.1.0)

Erstattungen und Kündigungen werden nicht aus dem CMS an den Anbieter gesendet (im Dashboard des Anbieters erledigen; eine Kündigung dort kommt als Ereignis zurück). PayPal direkt (ohne Mollie) ist nicht angebunden. Rechnungen: Bereich 5b.

### Technik

| Baustein | Datei |
|---|---|
| Dienst | `CMS/core/Services/Payments/PaymentService.php` |
| Gateways | `StripeGateway.php`, `MollieGateway.php`, `AbstractGateway.php`, `PaymentGatewayInterface.php` |
| Einstellungen | `PaymentSettings.php` (Gruppe `payments`) |
| Routen | `CMS/core/Routing/PaymentRouter.php` |
| Admin | `CMS/admin/payments.php`, `CMS/admin/views/subscriptions/payments.php` |
| Tabelle | `cms_payment_events`; Spalten `orders.provider`, `provider_checkout_id`, `paid_at`; `user_subscriptions.provider`, `provider_subscription_id`, `current_period_end` |
| Tests | `php TESTS/payments/run.php` |
