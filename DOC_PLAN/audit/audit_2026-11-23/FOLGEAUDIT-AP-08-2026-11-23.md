# AP-08 – Zahlungen, Abos, Bestellungen, Webhooks (Version 4.2.8)

**Stand 24.11.2026.** Geprüft wurde `main` nach `12701b8` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung, Skriptsuchen und Funktionsaufrufe
ohne Datenbank. Maßstab: [Audit-Katalog, AP-08](../AUDIT-KATALOG-4.2.0.md#ap-08-zahlungen-abos-bestellungen-webhooks). Der Bericht betrifft nur AP-08; er ist keine Freigabe des
gesamten Bereichs. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur Repository-Wurzel.

**Ergebnis:** Kein Befund in den gelesenen Pfaden; keine Codeänderung. Mehrere Punkte brauchen Datenbank und Anbieter-Sandbox und bleiben offen.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 08.1 Callbacks | ja | Stripe: `Stripe-Signature` mit HMAC-SHA-256 über `t.Payload`, `hash_equals`, mehrere `v1`, leeres Geheimnis abgelehnt, Zeitfenster beidseitig (`CMS/core/Services/Payments/StripeGateway.php:61–92`); Funktionsaufruf mit zehn Fällen (gültig, zu alt, Zukunft, falsches Geheimnis, veränderter Inhalt, leeres Geheimnis, ohne `t`, leere Signatur, zwei `v1`, doppeltes `t`) wie erwartet. Idempotenz je Ereignis-ID mit eindeutigem Schlüssel `(provider, provider_event_id)`, Bestell- und Folgeschritte in einer Transaktion, bei internem Fehler wird der Eintrag entfernt, damit die Wiederholung greift (`PaymentService.php:313–372`, `SchemaManager.php:1712`). Zuordnung der Prüf-ID (`:416`). | Mollie: Abruf beim Anbieter (nicht neu geprüft); veraltete Ereignisse (Reihenfolge) nicht geprüft. |
| 08.2 Betrag, Währung | ja | Betrag und Währung des Ereignisses werden gegen die Bestellung verglichen (`PaymentService.php:435–445`, Abo-Verlängerung `:651–660`); `amountCents()` rundet mit `round()`: 19,99 → 1999, 1,005 → 101, negativ und Text → 0, danach Abbruch bei Betrag ≤ 0 (`:247`). | Steuer und Storno auf der Datenbank. |
| 08.3 Abo-Zustände | offen | Nicht geprüft. | Zustandsautomat, Wettlauf, `cms_payment_refunded`. |
| 08.4 Rechnungen | offen (Teil bestätigt) | Nummernvergabe in einer Transaktion mit Sperre (`SELECT … FOR UPDATE`), Savepoint, Prüfung auf doppelte Nummer, PDF-Datei mit `xb` und 0640, Wiederholung bei Konflikt (`InvoiceService.php:976–1030`). | Zugriffsrechte beim Abruf, PDF-Grenzen, Last mit zwei Verbindungen auf MariaDB. |
| 08.5 Bestellnummer | offen | Nicht geprüft; die Suite `payments` deckt `formatOrderNumber()` ab. | `provisionalOrderNumber()`, Verlängerungssuffix (`PaymentService.php:666`). |
| 08.6 Zeitzonen | offen | Nicht geprüft. | Mit AP-10. |
| 08.7 Ausgehende Webhooks | offen (Teil bestätigt) | Signatur `v1=HMAC(Zeitstempel.Körper)` (`CMS/core/Services/Webhooks/WebhookService.php:102`), Ziel-Adresse mit `validateUrl()` und `Http\Client::isNonPublicIp()` (`:137–160`), Zustellung über den SSRF-sicheren Client mit Timeout (`:606`), Geheimnis verschlüsselt gespeichert (`:183, 378`). | Wiederholung mit Grenze und Deaktivierung, Löschfrist, einmalige Anzeige des Geheimnisses. |
| 08.8 Test-/Live-Modus | offen | Nicht geprüft. | Getrennte Schlüssel je Modus. |

## Abweichungen

Keine. Im gelesenen Umfang entspricht die Zahlungsverarbeitung den Prüfpunkten 08.1, 08.2 und dem Teil der Rechnungsvergabe.

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux. Funktionsaufrufe für `StripeGateway::verifySignature()` und `AbstractGateway::amountCents()`; die Suiten `payments`, `invoices`, `webhooks` bleiben im Gesamtlauf grün.

**Offen:** 08.3, 08.5, 08.6, 08.8 vollständig; 08.4 und 08.7 teilweise; Anbieter-Sandbox (Stripe, Mollie) und MariaDB-Lasttest für den Nummernkreis.
