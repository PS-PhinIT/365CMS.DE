# 365CMS Projektdokumentation | Admin: Zahlungen

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
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
- **Idempotenz:** Jedes Ereignis wird genau einmal verarbeitet: `cms_payment_events` ist eindeutig je (`provider`, `provider_event_id`); wiederholte Zustellungen ändern nichts. Der Zustandsautomat der Bestellung kennt keine Rücksprünge (`paid` wird nie wieder `pending`).
- Beträge und Währung nie aus dem Request; Abweichung → Ereignis `failed`, Bestellung bleibt offen.
- Schlüssel verschlüsselt (`SettingsService`), getrennt für Test und Live; die Seite zeigt nur „gesetzt“.
- Webhooks nach außen (`order.paid`, `order.failed`, `subscription.renewed`) enthalten nur IDs.

### Grenzen

Erstattungen werden im Dashboard des Anbieters ausgelöst. Seit 4.1.7 verarbeitet das CMS die Rückmeldung: Eine vollständige Erstattung oder Rückbuchung (Stripe `charge.refunded`, `charge.dispute.created`; Mollie `amountRefunded`/`amountChargedBack`) setzt die Bestellung auf `refunded`, erzeugt die Stornorechnung und beendet das zugehörige Abo, auch beim Anbieter. Teilerstattungen ändern den Status nicht. Bei Stripe die beiden Ereignisse im Webhook ergänzen. Nach erfolgreicher Verarbeitung kommt `cms_subscription_ended` zuerst, danach `cms_payment_refunded($orderId, $provider, $event)`.

Kündigungen gehen seit 4.1.7 vom CMS an den Anbieter (Stripe: Abo löschen bzw. `cancel_at_period_end`, Mollie: Abo des Kunden löschen):
- **Tarifwechsel:** Beim Zuweisen eines neuen Pakets wird das wiederkehrende Abo des alten Pakets beim Anbieter beendet (keine Doppelabbuchung).
- **Kündigung durch Mitglieder:** unter *Mitgliederbereich → Abo* mit Bestätigung. Das Abo bleibt bis zum Ende der bezahlten Periode (`end_date`) aktiv, danach endet der Zugang. Hook `cms_subscription_cancelled_by_member`.
- Schlägt der Aufruf beim Anbieter fehl, wird das protokolliert (Kanal `payments`) und das Mitglied erhält eine Fehlermeldung. Dann im Dashboard des Anbieters kündigen.

Bleibt die Verlängerung eines Abos mit wiederkehrender Zahlung beim Anbieter aus (verspäteter Webhook, Wiederholung der Abbuchung), behält das Mitglied den Zugang bis zu `grace_period_days` Tage über das Laufzeitende hinaus (Standard 3, siehe [PACKAGES.md](PACKAGES.md)); die Seite *Mitgliederbereich → Abo* zeigt „Nächste Verlängerung“ nur für solche Abos, sonst „Läuft bis“. Abos per Rechnung, gekündigte und erstattete Abos enden mit `end_date` (seit 4.1.9).

Verlängerungen und Anbieter-Abos (seit 4.1.9):
- **Mollie-Abo:** Es entsteht erst nach dem Abschluss der Datenbank-Transaktion der Erstzahlung. Bricht die Verarbeitung ab und der Anbieter wiederholt das Ereignis, entsteht dadurch kein zweites Abo.
- **Fehlgeschlagene Verlängerung:** `cms_payment_failed` nennt die letzte Bestellung des Abos (`order_id` im Webhook-Ereignis `order.failed`) statt 0.
- **Folgebestellung:** Vorlage ist die letzte bezahlte Bestellung beim selben Anbieter, sonst die letzte bezahlte oder erstattete Bestellung des Pakets. Gibt es keine, bleibt die Verlängerung verbucht, es entsteht aber keine Folgebestellung und keine Rechnung; das steht im Log (Kanal `payments`), und der Hook `cms_subscription_renewal_unbilled` wird ausgelöst.

### Zeitzonen

Die Unix-Zeitstempel von Stripe/Mollie beschreiben instanztlose Zeitpunkte. `user_subscriptions.current_period_end` speichert das vom Anbieter bzw. aus dessen Ereignis ermittelte Periodenende unverändert als **UTC** (`gmdate()`). Dagegen liegen die vom übrigen Core mit `NOW()` verglichenen Spalten `orders.created_at`, `orders.paid_at`, `user_subscriptions.end_date`, `user_subscriptions.next_billing_date` und `user_subscriptions.cancelled_at` in der **PHP-/Datenbank-Ortszeit** (`date()`; die Datenbank-Sitzungszone wird an PHP angeglichen).

Dasselbe Ablaufdatum kann deshalb in `current_period_end` und in `end_date`/`next_billing_date` bei einer von UTC abweichenden Zone eine andere Uhrzeit zeigen; es bleibt derselbe Zeitpunkt. `payment_events` wird getrennt davon in UTC protokolliert. Provider-Ende wird nie vor dem Speichern in Ortszeit umgerechnet, und die Ortszeitspalten werden nicht mit UTC-Zeichenketten befüllt.

PayPal direkt (ohne Mollie) ist nicht angebunden. Rechnungen: Bereich 5b.

### Technik

| Baustein | Datei |
|---|---|
| Dienst | `CMS/core/Services/Payments/PaymentService.php` |
| Gateways | `StripeGateway.php`, `MollieGateway.php`, `AbstractGateway.php`, `PaymentGatewayInterface.php` |
| Einstellungen | `PaymentSettings.php` (Gruppe `payments`) |
| Routen | `CMS/core/Routing/PaymentRouter.php` |
| Admin | `CMS/admin/payments.php`, `CMS/admin/views/subscriptions/payments.php` |
| Tabelle | `cms_payment_events`; Spalten `orders.provider`, `provider_checkout_id`, `paid_at`; `user_subscriptions.provider`, `provider_subscription_id`, `current_period_end` |
