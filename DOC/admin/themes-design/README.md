# 365CMS – Projektdokumentation | Abschnitt: Admin – Themes & Gestaltung

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **Themes & Design** (*Themes & Gestaltung*). It covers theme management, the theme customizer (`/admin/theme-editor`), the theme file explorer, menus, the landing page, the font manager, the CMS login page and the theme marketplace. All pages require role `admin` and capability `manage_settings`.

## Deutsch

### Menüpunkte

| Menüpunkt | Route | Dokument |
|---|---|---|
| Theme-Verwaltung | `/admin/themes` | [MARKETPLACE.md](MARKETPLACE.md#theme-verwaltung-adminthemes) |
| Theme-Editor (Customizer) | `/admin/theme-editor` | [CUSTOMIZER.md](CUSTOMIZER.md) |
| Theme-Explorer (Dateieditor) | `/admin/theme-explorer` | [EDITOR.md](EDITOR.md) |
| Theme-Menü | `/admin/menu-editor` | [MENUS.md](MENUS.md) |
| Landingpage | `/admin/landing-page` | [../landing-page/LANDING-PAGE.md](../landing-page/LANDING-PAGE.md) |
| Schriftverwaltung | `/admin/font-manager` | [FONTS.md](FONTS.md) |
| CMS-Loginseite | `/admin/cms-loginpage` | [CMS-LOGINPAGE.md](CMS-LOGINPAGE.md) |
| Theme-Marktplatz | `/admin/theme-marketplace` (nur wenn `marketplace_enabled`) | [MARKETPLACE.md](MARKETPLACE.md) |

### Weiterleitungen und Alt-Routen

| Alte Route | Ziel |
|---|---|
| `/admin/design-settings` | `/admin/theme-editor` ([DESIGN-SETTINGS.md](DESIGN-SETTINGS.md)) |
| `/admin/theme-settings` | `/admin/settings` |
| `/admin/theme-customizer` | `/admin/theme-editor` |
| `/admin/menus` | `/admin/menu-editor` |
| `/admin/fonts-local` | `/admin/font-manager` |

### Mitgeliefertes Theme

`CMS/themes/cms-default/` – „Meridian CMS Default“, Version **1.0.9** (`theme.json`). Es bringt einen eigenen Customizer (`admin/customizer.php`) mit neun Bereichen und registriert acht Menüpositionen. Entwicklung eigener Themes: [../../theme/THEME-DEVELOPMENT.md](../../theme/THEME-DEVELOPMENT.md).

### Hinweis zu DASHBOARD-WIDGETS.md

Die Widget-Konfiguration des Mitglieder-Dashboards gehört fachlich zum Bereich *Mitglieder-Dashboard*. [DASHBOARD-WIDGETS.md](DASHBOARD-WIDGETS.md) verweist dorthin.
