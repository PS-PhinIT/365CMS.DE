# 365CMS – Projektdokumentation | Abschnitt: Admin – Aboverwaltung

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **Subscriptions** (*Aboverwaltung*): packages, orders/assignments and global subscription settings. All pages require `manage_settings` and the corresponding core modules (see [SUBSCRIPTION-SYSTEM.md](SUBSCRIPTION-SYSTEM.md)).

## Deutsch

| Menüpunkt | Route | Core-Modul | Dokument |
|---|---|---|---|
| Pakete & Abo-Einstellungen | `/admin/packages` | `subscription_admin_packages` | [PACKAGES.md](PACKAGES.md) |
| Bestellungen & Zuweisung | `/admin/orders` | `subscription_admin_orders` | [ORDERS.md](ORDERS.md) |
| Einstellungen | `/admin/subscription-settings` | `subscription_admin_settings` | [SUBSCRIPTION-SYSTEM.md](SUBSCRIPTION-SYSTEM.md) |

Die Gruppe erscheint nur, wenn das Core-Modul `subscriptions` aktiv ist (`/admin/modules`).

### Typischer Ablauf

1. **Pakete anlegen** – `seed_defaults` oder eigene Pakete mit Preisen, Limits und Plugin-Freigaben.
2. **Abrechnung konfigurieren** – Steuer, Rechnungsnummern, Testphase, AGB-/Widerrufsseite (ebenfalls auf `/admin/packages`).
3. **Schalter setzen** – Limits, Member-Bereich, Bestellungen, öffentliche Preise, Standardpaket (`/admin/subscription-settings`).
4. **Bestellungen bearbeiten** – Checkout erzeugt `pending`-Bestellungen; nach Zahlungseingang Status `paid` setzen und Abo zuweisen (`/admin/orders`).
5. **Gruppen nutzen** – Paket einer Gruppe zuordnen, um mehreren Benutzern gemeinsam Rechte zu geben ([../users-groups/GROUPS.md](../users-groups/GROUPS.md)).

### Verwandte Dokumente

- Mitgliedersicht: [../../member/MEMBER-ROUTES.md](../../member/MEMBER-ROUTES.md)
- Plugin-Integration: [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)
- Datenbank: [../../core/DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md)
