# 365CMS Projektdokumentation | Admin: Bestellungen & Zuweisung

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **Route:** `/admin/orders` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_orders`

## English (summary)

Orders and manual subscription assignment are handled at `/admin/orders` (`CMS/admin/orders.php` → `CMS/admin/modules/subscriptions/OrdersModule.php` → `CMS/admin/views/subscriptions/orders.php`). Orders are stored in `cms_orders`, assignments in `cms_user_subscriptions`.

- Status filter: `pending`, `paid`, `cancelled`, `refunded`, `failed` (aliases `confirmed`/`completed` → `paid`).
- Actions: `assign_subscription` (user, plan, cycle `monthly|yearly|lifetime`), `update_status`, `delete`.
- CSV export: `?export=orders` or `?export=usage` (respects the status filter).

## Deutsch

### Herkunft der Bestellungen

Bestellungen entstehen im öffentlichen Checkout `CMS/orders.php` (Aufruf `/orders.php?plan=<id>&billing=monthly|yearly|lifetime`). Der Checkout

1. ist nur erreichbar, wenn die Core-Module `subscriptions` und `subscription_ordering` aktiv sind (sonst Weiterleitung auf `/`),
2. lädt ausschließlich aktive Pakete,
3. berechnet Netto/Steuer/Brutto aus `tax_rate` und `tax_included`,
4. verlinkt AGB- und Widerrufsseite aus den Paketeinstellungen,
5. prüft das CSRF-Token `checkout_process`,
6. fügt die Bestellung mit Status `pending` zunächst unter einer eindeutigen vorläufigen `order_number` ein und ersetzt sie anschließend anhand der echten Zeilen-ID durch die endgültige Nummer.

> Die Router-Route `GET|POST /order` (`PublicRouter::renderOrder()`/`handleOrder()`) bindet eine optionale `CMS/member/order_public.php` ein. Fehlt sie (Standard), leitet `/order` seit 3.4.12 auf `/orders.php` weiter (GET 302, POST 307, Query-String bleibt erhalten). Vorher lieferte die Route 404.

### Erzeugung der Bestellnummer

`PaymentService::provisionalOrderNumber()` liefert `TMP-` plus 32 zufällige Hex-Zeichen (insgesamt 36 Zeichen). Der Checkout benutzt diese Nummer nur für den INSERT. Dadurch hängt die Nummer nicht vom möglichen und unter MySQL veraltet zwischengespeicherten `AUTO_INCREMENT`-Wert aus `information_schema` ab; parallele Bestellungen erhalten bereits in der eindeutigen Spalte unterschiedliche Werte.

Nach dem INSERT erzeugt `PaymentService::formatOrderNumber($format, $orderId, $timestamp)` die endgültige Nummer mit der tatsächlich zurückgegebenen ID. Das gespeicherte Format `setting_order_number_format` fällt bei leerem Wert auf `BST{Y}{M}-{ID}` zurück. Unterstützte Platzhalter:

| Platzhalter | Wert |
|---|---|
| `{Y}` | Jahr mit vier Ziffern aus `$timestamp` |
| `{M}` | Monat mit zwei Ziffern |
| `{D}` | Tag mit zwei Ziffern |
| `{ID}` | echte ID, mindestens vierstellig links mit `0` aufgefüllt; längere IDs bleiben vollständig |
| `{R}` | vier großgeschriebene Hex-Zeichen aus zwei Zufallsbytes |

Fehlt `{ID}`, hängt die Methode `-{ID}` an, damit verschiedene Bestellungen eindeutig bleiben. Zulässig sind am Anfang ein ASCII-Buchstabe oder eine Ziffer und danach höchstens 55 Buchstaben, Ziffern, `-`, `_`, `.` oder `/`. Leeres bzw. ungültiges oder zu langes Format fällt auf `BST{Y}{M}-{ID}` zurück; die Spalte erlaubt 64 Zeichen und Verlängerungen reservieren Platz für `-Vxxxxxx`. `{Y}`, `{M}` und `{D}` werden mit der PHP-Ortszeitzone (`date()`) ausgewertet.

Schlägt nur das nachträgliche Setzen der endgültigen Nummer fehl, bleibt die Bestellung gültig mit dem `TMP-…`-Wert; der Fehler wird mit Kanal `orders.checkout` protokolliert. Folgebestellungen von Online-Verlängerungen ersetzen eine vorhandene Endung `-Vxxxxxx`, hängen stattdessen `-V` plus sechs Zeichen des Ereignis-Hashes an und umgehen `TMP` nicht durch eine Zahlenprognose.

### Oberfläche

- **Kennzahlen:** Umsätze, offene/bezahlte Bestellungen, aktive Abos, Ressourcen-Nutzung (Experten, Unternehmen, Events, Speaker, Speicher).
- **Filter:** `?status=pending|paid|cancelled|refunded|failed`.
- **Bestellliste:** Bestellnummer, Kunde, Paket, Zyklus, Betrag, Status, Datum.
- **Manuelle Zuweisung:** Benutzer + Paket + Abrechnungszyklus → aktives Abo ohne Bestellung (z. B. für Partner, Tests, Kulanz).

### Aktionen

| `action` | Parameter | Wirkung |
|---|---|---|
| `assign_subscription` | `user_id`, `plan_id`, `billing_cycle` (`monthly`, `yearly`, `lifetime`) | `SubscriptionManager::assignSubscription()`: setzt ein bestehendes aktives Abo auf `cancelled` und legt ein neues aktives Abo an |
| `update_status` | `id`, `status` | Bestellstatus ändern |
| `delete` | `id` | Bestellung löschen |

### Export

`GET /admin/orders?export=orders[&status=…]` bzw. `?export=usage` streamt eine CSV-Datei (Bestellungen bzw. Ressourcen-Nutzung je Benutzer). Fehler beim Export werden protokolliert (`orders.export_failed`).

### Datenmodell `cms_orders`

`order_number`, `user_id`, `plan_id`, `customer_name`, `customer_email`, `amount`, `tax_amount`, `total_amount`, `currency` (Standard `EUR`), `status`, `payment_method`, `billing_cycle`, `payment_ref`, `notes`, `contact_data` (JSON), Adressfelder (`forename`, `lastname`, `company`, `email`, `phone`, `street`, `zip`, `city`, `country`), Zeitstempel.

### Verwandte Dokumente

[PACKAGES.md](PACKAGES.md) · [SUBSCRIPTION-SYSTEM.md](SUBSCRIPTION-SYSTEM.md) · [../../member/MEMBER-ROUTES.md](../../member/MEMBER-ROUTES.md)
