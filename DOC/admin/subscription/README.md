# 365CMS – Projektdokumentation | Abschnitt: Admin – Aboverwaltung

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable

## English (summary)

This folder documents the admin area **Subscriptions** (*Aboverwaltung*): packages, orders/assignments and global subscription settings. All pages require `manage_settings` and the corresponding core modules (see [SUBSCRIPTION-SYSTEM.md](SUBSCRIPTION-SYSTEM.md)).

## Deutsch

| Menüpunkt | Route | Core-Modul | Dokument |
|---|---|---|---|
| Pakete & Abo-Einstellungen | `/admin/packages` | `subscription_admin_packages` | [PACKAGES.md](PACKAGES.md) |
| Bestellungen & Zuweisung | `/admin/orders` | `subscription_admin_orders` | [ORDERS.md](ORDERS.md) |
| Zahlungen (Stripe, Mollie) | `/admin/payments` | `subscription_admin_orders`, Recht `payments.manage` | [PAYMENTS.md](PAYMENTS.md) |
| Rechnungen | `/admin/invoices` | `subscription_admin_orders`, Recht `invoices.view` | [INVOICES.md](INVOICES.md) |
| Einstellungen | `/admin/subscription-settings` | `subscription_admin_settings` | [SUBSCRIPTION-SYSTEM.md](SUBSCRIPTION-SYSTEM.md) |

Die Gruppe erscheint nur, wenn das Core-Modul `subscriptions` aktiv ist (`/admin/modules`).

### Typischer Ablauf

1. **Pakete anlegen** – `seed_defaults` oder eigene Pakete mit Preisen, Limits und Plugin-Freigaben.
2. **Abrechnung konfigurieren** – Steuer, Rechnungsnummern, Testphase, AGB-/Widerrufsseite (ebenfalls auf `/admin/packages`).
3. **Schalter setzen** – Limits, Member-Bereich, Bestellungen, öffentliche Preise, Standardpaket (`/admin/subscription-settings`).
4. **Bestellungen bearbeiten** – Checkout erzeugt `pending`-Bestellungen. Online-Zahlungen (Stripe, Mollie) setzen `paid` und weisen das Abo automatisch zu, die Rechnung entsteht dabei automatisch; bei Zahlart Rechnung nach Zahlungseingang Status `paid` setzen, Abo zuweisen (`/admin/orders`) und die Rechnung unter `/admin/invoices` erstellen.
5. **Gruppen nutzen** – Paket einer Gruppe zuordnen, um mehreren Benutzern gemeinsam Rechte zu geben ([../users-groups/GROUPS.md](../users-groups/GROUPS.md)).

### Verwandte Dokumente

- Mitgliedersicht: [../../member/MEMBER-ROUTES.md](../../member/MEMBER-ROUTES.md)
- Plugin-Integration: [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)
- Datenbank: [../../core/DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md)
