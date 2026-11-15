# 365CMS Projektdokumentation | Admin: Webhooks

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** veröffentlicht
> **Route:** `/admin/webhooks` | **Capability:** `webhooks.manage` | **CSRF-Aktion:** `admin_webhooks`

## English (summary)

Outgoing webhooks notify external services (Zapier, Make, n8n, own endpoints) about CMS events. Admins create receivers with an HTTPS URL, a set of events and a 32-byte secret (stored encrypted, shown once). Events are queued in `cms_webhook_deliveries` and delivered by cron (`cms_cron_mail_queue`, otherwise hourly) as signed JSON `POST`s: `X-365CMS-Signature: v1=<hex HMAC-SHA256(secret, timestamp + "." + body)>`. Retries after 1, 5, 30, 120 and 720 minutes; HTTP 410 or 20 consecutive failures disable the receiver. Delivery goes through `Http\Client` (SSRF protection, 10 s timeout, response capped at 64 KB, only the status is used). Payloads carry IDs instead of personal data. Delivery logs are deleted after 30 days (retention rule `webhooks.deliveries`).

## Deutsch

### Empfänger verwalten

Menü *Einstellungen → Webhooks* (Kacheln) bzw. `/admin/webhooks`:

- **Neuer Empfänger:** Name, URL (nur **HTTPS**, keine internen Hosts, keine privaten IP-Adressen, keine Zugangsdaten in der URL), Ereignisse. Nach dem Anlegen erscheint das **Geheimnis einmal** in der Erfolgsmeldung, sofort beim Empfänger hinterlegen.
- Je Empfänger: Ändern, **Aktiv**-Schalter (Aktivieren setzt Fehlerzähler und Deaktivierungsgrund zurück), **Test senden** (Ereignis `webhook.test`, sofort zugestellt), **Geheimnis neu erzeugen**, Löschen (samt Zustellungen).
- **Zustellprotokoll:** die letzten 50 Zustellungen mit Ereignis, Status (ausstehend, zugestellt, aufgegeben), HTTP-Status, Versuchen und Fehlergrund, ohne Antwortinhalt und ohne Geheimnis.

### Ereignisse

| Ereignis | Auslöser | Daten |
|---|---|---|
| `backup.offsite_uploaded` | Offsite-Kopie erfolgreich | `backup`, `bytes` |
| `backup.offsite_failed` | Offsite-Kopie fehlgeschlagen | `backup`, `error` |
| `content.published` | Beitrag/Seite wechselt nach „veröffentlicht“ (Speichern, Freigabe, Sammelaktion; bei Datum in der Zukunft schon beim Planen) | `type`, `id` |
| `content.submitted` | Beitrag/Seite zur Prüfung eingereicht | `type`, `id`, `user_id` |
| `user.registered` | Registrierung | `user_id` |
| `invoice.created` | Rechnung oder Storno-Rechnung erstellt (Bereich 5b) | `invoice_id`, `type`, `order_id` |
| `order.paid`, `order.failed`, `subscription.renewed` | Zahlung bestätigt, fehlgeschlagen, Abo verlängert (Bereich 5a) | `order_id`, `provider` bzw. `subscription_id`, `order_id`, `period_end` |

**Nicht enthalten:** Das Ereignis `alert.raised` (Echtzeit-Alerting) gehört zu Bereich 6 und kommt mit **4.3.0** ([06-CLI-ALERTING.md](../../workflow/specs/v4.1/06-CLI-ALERTING.md)). Ein Test-Ereignis `webhook.test` sendet der Knopf „Test senden“.

Weitere Ereignisse liefern Plugins über den Filter `cms_webhook_event` (`['bereich.aktion' => 'Bezeichnung']`) und lösen sie mit `WebhookService::getInstance()->dispatch('bereich.aktion', [...])` aus.

### Zustellung und Signatur

`POST` mit JSON `{"id": "<uuid>", "event": "…", "created_at": "…", "data": {…}}` und den Kopfzeilen `Content-Type: application/json`, `User-Agent: 365CMS-Webhooks/1.0`, `X-365CMS-Event`, `X-365CMS-Delivery` (UUID), `X-365CMS-Timestamp` (Unix-Zeit) und `X-365CMS-Signature: v1=<hex>`.

Empfänger prüfen: `hex(HMAC-SHA256(geheimnis, timestamp + "." + body)) == signatur` (zeitkonstant vergleichen) und den Zeitstempel auf höchstens 5 Minuten Abweichung. Die `X-365CMS-Delivery`-ID eignet sich zum Erkennen von Wiederholungen.

- **2xx** = zugestellt. Sonst bis zu 5 Wiederholungen nach 1, 5, 30, 120 und 720 Minuten, danach „aufgegeben“.
- **410 Gone** deaktiviert den Empfänger sofort; **20 Fehlschläge in Folge** ebenfalls (Hook `cms_webhook_endpoint_disabled`).
- Zugestellt wird nur im Cron (`cms_cron_mail_queue` minütlich, sonst `cms_cron_hourly`), nie während einer Seitenanfrage.

### Technik

`CMS\Services\Webhooks\WebhookService`, `FeatureHooks\WebhookHooks`, Tabellen `cms_webhook_endpoints`, `cms_webhook_deliveries` ([DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md)), Löschfrist `webhooks.deliveries` (30 Tage, nur zugestellte und aufgegebene), Tests `php TESTS/webhooks/run.php`.

### Verwandte Dokumente

[BACKUP.md](BACKUP.md) · [../pages-posts/REVIEW.md](../pages-posts/REVIEW.md) · [../../core/HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md)
