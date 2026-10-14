# 365CMS – Projektdokumentation | Abschnitt: Admin – Dashboard-Widgets

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

Two kinds of dashboard widgets exist in 365CMS:

1. **Admin dashboard** (`/admin`) – KPI cards and panels rendered by `CMS/admin/modules/dashboard/DashboardModule.php` and `CMS/core/Services/DashboardService.php`; plugins can add their own panels via hooks. See [../dashboard/DASHBOARD.md](../dashboard/DASHBOARD.md).
2. **Member dashboard** (`/member`) – standard widgets, up to four custom widgets and plugin widgets, configured at `/admin/member-dashboard-widgets` and `/admin/member-dashboard-plugin-widgets`. See [../member/README.md](../member/README.md).

## Deutsch

### Mitglieder-Dashboard-Widgets

| Einstellung | Route | Option |
|---|---|---|
| Spaltenanzahl, sichtbare Standard-Widgets, Reihenfolge | `/admin/member-dashboard-widgets` | `member_dashboard_columns`, `member_dashboard_widgets` |
| Bereichsreihenfolge (Schnellstart, Statistiken, Widgets, Plugins) | `/admin/member-dashboard-widgets` | `member_dashboard_section_order` |
| Eigene Widgets 1–4 (Titel, Inhalt, Icon) | `/admin/member-dashboard-widgets` | `member_widget_<n>_title/_content/_icon`, `member_dashboard_custom_widget_order` |
| Plugin-Widgets (Sichtbarkeit, Titel, Beschreibung, Icon, Farbe, Reihenfolge) | `/admin/member-dashboard-plugin-widgets` | `member_dashboard_plugin_order` und Plugin-Metadaten |
| Bereiche ein-/ausblenden | `/admin/member-dashboard-frontend-modules` | `member_dashboard_show_*` |

Plugins melden Widgets über `CMS\Member\PluginDashboardRegistry` (`CMS/core/Member/PluginDashboardRegistry.php`) an; Einzelheiten zur Registrierung in [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md).

### Admin-Dashboard-Widgets

Das Admin-Dashboard zeigt Kennzahlen zu Inhalten, Benutzern, Kommentaren, Sicherheit und System sowie Hinweise (z. B. ausstehende Kommentare, Updates). Die Zusammenstellung ist fest im Code; Plugins ergänzen eigene Bereiche über Hooks. Details: [../dashboard/DASHBOARD.md](../dashboard/DASHBOARD.md).

### Verwandte Dokumente

[../member/README.md](../member/README.md) · [../dashboard/DASHBOARD.md](../dashboard/DASHBOARD.md) · [../../member/MEMBER-DASHBOARD.md](../../member/MEMBER-DASHBOARD.md)
