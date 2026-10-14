# 365CMS – Projektdokumentation | Abschnitt: Admin – Abo-Pakete

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/packages` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_packages`

## English (summary)

Subscription packages (plans) are managed at `/admin/packages` (`CMS/admin/packages.php` → `CMS/admin/modules/subscriptions/PackagesModule.php` → `CMS/admin/views/subscriptions/packages.php`). Plans live in `cms_subscription_plans`. Actions: `save`, `toggle`, `delete`, `seed_defaults`, `save_package_settings`. The same page also holds the billing/package settings (trial, renewal, invoice numbering, tax, legal pages) handled by `SubscriptionSettingsModule::savePackageSettings()`.

## Deutsch

### Paketliste

Die Seite zeigt alle Pakete mit Preisen, Limits, Status (aktiv/inaktiv), Hervorhebung („empfohlen“) und Sortierung.

| Aktion | Wirkung |
|---|---|
| `save` | Paket anlegen/ändern (Name Pflicht, Slug eindeutig) |
| `toggle` | Aktiv/inaktiv umschalten |
| `delete` | Paket löschen – **abgelehnt**, solange aktive Benutzer zugewiesen sind |
| `seed_defaults` | Legt die sechs Standardpakete an bzw. ergänzt fehlende; „Professional“ wird als empfohlen markiert |
| `save_package_settings` | Abrechnungseinstellungen speichern (siehe unten) |

### Standardpakete (`SubscriptionManager::seedDefaultPlans()`)

| Slug | Name | Monatlich | Jährlich |
|---|---|---|---|
| `free` | Free | 0,00 € | 0,00 € |
| `basic` | Basic | 9,99 € | 99,00 € |
| `professional` | Professional (empfohlen) | 29,99 € | 299,00 € |
| `business` | Business | 79,99 € | 799,00 € |
| `premium` | Premium | 149,99 € | 1 499,00 € |
| `enterprise` | Enterprise | 499,99 € | 4 999,00 € |

### Felder eines Pakets

| Feld | Spalte | Hinweis |
|---|---|---|
| Name, Slug, Beschreibung | `name`, `slug`, `description` | Slug: Kleinbuchstaben, Ziffern, Bindestrich |
| Preis monatlich / jährlich | `price_monthly`, `price_yearly` | Dezimal, Währung EUR |
| Limits | `limit_experts`, `limit_companies`, `limit_events`, `limit_speakers`, `limit_storage_mb` | `-1` = unbegrenzt; Standard Speicher 1000 MB |
| Plugin-Freigaben | `plugin_experts`, `plugin_companies`, `plugin_events`, `plugin_speakers` | Zugriff auf die jeweiligen Plugin-Bereiche |
| Feature-Flags | `feature_analytics`, `feature_advanced_search`, `feature_api_access`, `feature_custom_branding`, `feature_priority_support`, `feature_export_data`, `feature_integrations`, `feature_custom_domains` | Werden von Plugins/Theme über `SubscriptionManager` abgefragt |
| Aktiv, Sortierung, empfohlen | `is_active`, `sort_order`, Option „featured“ | Inaktive Pakete sind im Checkout nicht bestellbar |

### Abrechnungs- und Paketeinstellungen (`save_package_settings`)

| Feld | Option | Standard |
|---|---|---|
| Abos aktiv | `subscription_enabled` | `1` |
| Testphase / Tage | `trial_enabled`, `trial_days` | aus / 14 |
| Automatische Verlängerung | `auto_renewal` | an |
| Kulanzzeit nach Ablauf (Tage) | `grace_period_days` | 3 |
| Kündigungsfrist (Tage) | `cancellation_period_days` | 0 |
| Zahlungsarten | `payment_methods` | `invoice` (weitere: `stripe`, `paypal`, `all`) |
| Rechnungspräfix / nächste Nummer | `invoice_prefix`, `invoice_next_number` | `INV-` / 1001 |
| Steuersatz / inkl. MwSt. | `tax_rate`, `tax_included` | 19 % / ja |
| Erinnerung vor Ablauf (Tage) / E-Mail | `notification_before_expiry`, `notification_email` | 7 / leer |
| AGB-Seite / Widerrufsseite | `terms_page_id`, `cancellation_page_id` | – (Seiten-ID aus `cms_pages`) |

> **Hinweis:** Stripe und PayPal werden im Checkout als Zahlungsart angeboten, eine Zahlungsabwicklung (Gateway-Anbindung) ist im Core nicht enthalten. Bestellungen werden mit Status `pending` angelegt und in `/admin/orders` manuell auf `paid` gesetzt.

### Verwandte Dokumente

[ORDERS.md](ORDERS.md) · [SUBSCRIPTION-SYSTEM.md](SUBSCRIPTION-SYSTEM.md) · [../users-groups/GROUPS.md](../users-groups/GROUPS.md)
