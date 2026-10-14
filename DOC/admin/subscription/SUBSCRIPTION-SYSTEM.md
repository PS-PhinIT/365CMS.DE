# 365CMS – Projektdokumentation | Abschnitt: Admin – Abo-System & Einstellungen

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/subscription-settings` (Alt-Route `/admin/subscriptions` leitet um) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_sub_settings`

## English (summary)

The subscription system consists of `CMS\SubscriptionManager` (`CMS/core/SubscriptionManager.php`), the tables `cms_subscription_plans`, `cms_user_subscriptions`, `cms_subscription_usage`, `cms_orders`, group plans via `cms_user_groups.plan_id`, and a set of core modules (`subscriptions`, `subscription_limits`, `subscription_member_area`, `subscription_ordering`, `subscription_public_pricing`, plus the three admin page modules). The settings page `/admin/subscription-settings` (`SubscriptionSettingsModule`) stores the master switches and the default plan; those switches are the legacy settings behind the core modules.

Resolution order of a user's plan: active direct subscription → plan of an active group → first plan in the table (fallback "free").

## Deutsch

### Bausteine

| Baustein | Datei / Tabelle |
|---|---|
| Laufzeit-API | `CMS/core/SubscriptionManager.php` |
| Pakete | `cms_subscription_plans` ([PACKAGES.md](PACKAGES.md)) |
| Abos der Benutzer | `cms_user_subscriptions` (`status`: `active`, `cancelled`, `expired`, `trial`, `suspended`; `billing_cycle`: `monthly`, `yearly`, `lifetime`; `start_date`, `end_date`, `next_billing_date`, `cancelled_at`) |
| Ressourcen-Nutzung | `cms_subscription_usage` |
| Gruppen-Pakete | `cms_user_groups.plan_id` ([../users-groups/GROUPS.md](../users-groups/GROUPS.md)) |
| Bestellungen | `cms_orders` ([ORDERS.md](ORDERS.md)) |
| Mitgliederansicht | `/member/subscription` (`CMS/member/subscription.php`) |
| Checkout | `CMS/orders.php` |

### Einstellungen auf `/admin/subscription-settings`

| Feld | Option | Standard | Wirkung |
|---|---|---|---|
| Limits & Zugriffsgates | `subscription_limits_enabled` | an | Plugin-Freigaben und Ressourcenlimits werden durchgesetzt |
| Member-Abo-Bereich | `subscription_member_area_enabled` | an | `/member/subscription` sichtbar |
| Bestellungen & Upgrades | `subscription_ordering_enabled` | an | Checkout `/orders.php` erlaubt |
| Öffentliche Preise | `subscription_public_pricing_enabled` | an | Paketübersicht für Besucher |
| Standardpaket | `subscription_default_plan_id` | 0 (keins) | Wird neuen Benutzern zugewiesen (`assignConfiguredDefaultPlan()`) |
| Hinweis bei deaktivierter Aboverwaltung | `subscription_disabled_notice` | „Die Aboverwaltung ist derzeit deaktiviert. Es gelten aktuell keine Limits.“ | Text im Member-Bereich |

Diese Schalter sind die *Legacy-Settings* der gleichnamigen Core-Module. Der Modul-Manager (`/admin/modules`) zeigt denselben Zustand; ein Modul ist nur aktiv, wenn auch seine Abhängigkeit `subscriptions` aktiv ist.

### Core-Module der Aboverwaltung

| Modul | Label | Abhängigkeit | Legacy-Setting |
|---|---|---|---|
| `subscriptions` | Aboverwaltung Core | – | `subscription_enabled` |
| `subscription_admin_packages` | Pakete & Abo-Einstellungen (`/admin/packages`) | `subscriptions` | – |
| `subscription_admin_orders` | Bestellungen & Zuweisung (`/admin/orders`) | `subscriptions` | – |
| `subscription_admin_settings` | Abo-Einstellungen (`/admin/subscription-settings`) | `subscriptions` | – |
| `subscription_limits` | Paketlimits & Zugriffsgates | `subscriptions` | `subscription_limits_enabled` |
| `subscription_member_area` | Member-Abo-Bereich | `subscriptions` | `subscription_member_area_enabled` |
| `subscription_ordering` | Bestell- & Upgrade-Prozesse | `subscriptions` | `subscription_ordering_enabled` |
| `subscription_public_pricing` | Öffentliche Paketkommunikation | `subscriptions` | `subscription_public_pricing_enabled` |

### Laufzeit-API (`SubscriptionManager::instance()`)

| Methode | Zweck |
|---|---|
| `getUserSubscription(int $userId)` | Aktives Paket ermitteln: direktes Abo (`active`, `end_date` leer oder in der Zukunft) → Paket einer aktiven Gruppe (teuerstes zuerst) → erster Paket-Datensatz als Fallback |
| `isLimitEnforcementEnabled()` | Modul `subscription_limits` aktiv? |
| `canAccessPlugin($userId, $slug)` | Prüft `plugin_<slug>` des Pakets (z. B. `plugin_events`); ohne Limitdurchsetzung immer `true` |
| `checkLimit($userId, $resource)` | Liest `limit_<resource>` des Pakets (`experts`, `companies`, `events`, `speakers`, `storage_mb`) und vergleicht mit der aktuellen Nutzung: `-1` = unbegrenzt, `0` = gesperrt, sonst `Nutzung < Limit` |
| `getCurrentUsage()` / `updateUsage()` | Zähler in `cms_subscription_usage` lesen/schreiben |
| `assignSubscription($userId, $planId, $cycle)` | Neues aktives Abo, bisheriges wird `cancelled` |
| `assignConfiguredDefaultPlan($userId)` | Standardpaket zuweisen |
| `getRenewalSettings()`, `getSubscriptionRenewalNotice()`, `getRenewalOverview()` | Verlängerungs- und Ablaufhinweise (Kulanzzeit, Erinnerung vor Ablauf) |
| `getAllPlans()`, `getPlan()`, `seedDefaultPlans()` | Paketverwaltung |

Plugins nutzen diese API, um Funktionen paketabhängig freizuschalten:

```php
$subs = \CMS\SubscriptionManager::instance();
if (!$subs->canAccessPlugin($userId, 'events') || !$subs->checkLimit($userId, 'events')) {
    // Upgrade-Hinweis anzeigen
}
```

### Hinweise

- Ist die Limitdurchsetzung aus, gelten keine Paketgrenzen – auch nicht für Free-Benutzer.
- Der Fallback „erster Paket-Datensatz“ setzt voraus, dass das kostenlose Paket zuerst angelegt wurde (bei `seed_defaults` der Fall).
- Zahlungseingänge werden nicht automatisch verbucht; siehe [ORDERS.md](ORDERS.md).

### Verwandte Dokumente

[README.md](README.md) · [PACKAGES.md](PACKAGES.md) · [ORDERS.md](ORDERS.md) · [../system-settings/README.md](../system-settings/README.md)
