# 365CMS – Projektdokumentation | Abschnitt: Admin – Core-Module (Feature-Schalter)

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/modules` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_modules`

## English (summary)

`/admin/modules` (`CMS/admin/modules.php` → `CMS/admin/modules/system/ModulesModule.php` → `CMS/admin/views/system/modules.php`) switches whole core feature areas on or off. Definitions live in `CMS\Services\CoreModuleService::MODULES`; states are stored in the settings group `core_modules`. A disabled module hides its pages in every sidebar profile. Groups and tile pages left empty disappear, so the optional groups SEO, AI services, members and subscriptions appear only while their module is active, or while a plugin places its menu there ([../ADMIN-MENU.md](../ADMIN-MENU.md)). Its admin entry points refuse access (`isAdminPageEnabled()`), and runtime features check `isModuleEnabled()`. Dependencies are resolved automatically; some modules mirror a legacy setting.

## Deutsch

### Module

| Slug | Bezeichnung | Admin-Seiten | Abhängigkeit | Legacy-Setting |
|---|---|---|---|---|
| `ai_services` | AI Services | `ai-services`, `ai-translation`, `ai-content-creator`, `ai-seo-creator`, `ai-settings` (+ Editor.js-Übersetzungs- und SEO-Endpunkte) | – | – |
| `seo` | SEO | `seo-dashboard`, `analytics`, `seo-audit`, `seo-meta`, `seo-social`, `seo-schema`, `seo-sitemap`, `seo-search-engines`, `seo-technical`, `redirect-manager`, `not-found-monitor` | – | – |
| `security` | Sicherheit | `antispam`, `firewall`, `security-audit` | – | – |
| `legal` | Recht | `legal-sites`, `cookie-manager`, `data-requests`, `privacy-requests`, `deletion-requests` | – | – |
| `member_dashboard` | Member Dashboard | alle `member-dashboard*`-Seiten | – | `member_dashboard_enabled` |
| `performance` | Performance | `performance`, `performance-cache`, `-media`, `-database`, `-settings`, `-sessions` | – | – |
| `subscriptions` | Aboverwaltung Core | – | – | `subscription_enabled` |
| `subscription_admin_packages` | Pakete & Abo-Einstellungen | `packages` | `subscriptions` | – |
| `subscription_admin_orders` | Bestellungen & Zuweisung | `orders` | `subscriptions` | – |
| `subscription_admin_settings` | Abo-Einstellungen | `subscription-settings` | `subscriptions` | – |
| `subscription_limits` | Paketlimits & Zugriffsgates | – | `subscriptions` | `subscription_limits_enabled` |
| `subscription_member_area` | Member-Abo-Bereich | – | `subscriptions` | `subscription_member_area_enabled` |
| `subscription_ordering` | Bestell- & Upgrade-Prozesse | – | `subscriptions` | `subscription_ordering_enabled` |
| `subscription_public_pricing` | Öffentliche Paketkommunikation | – | `subscriptions` | `subscription_public_pricing_enabled` |

Alle Module sind standardmäßig **aktiv**. Die Abo-Untermodule sind hier nicht einzeln schaltbar (`toggleable = false`); sie folgen dem Modul `subscriptions` bzw. ihren Legacy-Settings unter `/admin/subscription-settings`.

### Wirkung eines deaktivierten Moduls

1. Die Seiten des Moduls verschwinden in jedem Sidebar-Profil. `AdminMenuService` prüft dafür je Menüeintrag `CoreModuleService::isAdminPageEnabled('<seite>')`. Leere Gruppen und Kachelseiten entfallen. Deshalb erscheinen die optionalen Gruppen *SEO*, *KI-Dienste*, *Mitgliederbereich* und *Abos* nur bei aktivem Modul. Ausnahme: Ein Plugin hat die Gruppe als Wunschplatz gewählt. Der Modulzustand ist Teil des Menü-Cache-Schlüssels, siehe [../ADMIN-MENU.md](../ADMIN-MENU.md#9-cache-und-sidebar-zustand).
2. Die Admin-Einstiege prüfen `CoreModuleService::isAdminPageEnabled('<seite>')` und verweigern den Zugriff.
3. Laufzeitfunktionen fragen `isModuleEnabled('<slug>')` ab, z. B.:
   - `seo` aus → keine erweiterten SEO-Felder im Editor,
   - `ai_services` aus → keine KI-Buttons im Editor, Endpunkte gesperrt,
   - `legal` aus → kein Cookie-Banner,
   - `subscription_ordering` aus → Checkout leitet auf `/` um.
4. Daten bleiben erhalten; beim Wiedereinschalten ist alles wie vorher.

### Abfrage im Code

```php
use CMS\Services\CoreModuleService;

if (CoreModuleService::getInstance()->isModuleEnabled('seo')) {
    // SEO-spezifische Ausgabe
}
```

Unbekannte Slugs gelten als aktiv (`isModuleEnabled()` liefert `true`).

### Verwandte Dokumente

[README.md](README.md) · [../subscription/SUBSCRIPTION-SYSTEM.md](../subscription/SUBSCRIPTION-SYSTEM.md) · [../README.md](../README.md)
