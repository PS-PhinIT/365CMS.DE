# 365CMS – Projektdokumentation | Abschnitt: Admin – Mitglieder-Dashboard

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/member-dashboard` (+ Unterseiten) | **Capabilities:** `manage_settings` bzw. `manage_users` | **CSRF-Aktion:** `admin_member_dashboard`

## English (summary)

The sidebar group **Member Dashboard** configures the personal member area under `/member`. Entry `CMS/admin/member-dashboard.php` shows an overview; each section has its own entry `CMS/admin/member-dashboard-<section>.php` which is rendered via `member-dashboard-page.php`. Logic lives in `CMS/admin/modules/member/MemberDashboardModule.php`, views in `CMS/admin/views/member/`. All values are stored as `member_*` / `member_dashboard_*` options in `cms_settings`.

Sections: `general`, `design`, `frontend-modules`, `widgets`, `plugin-widgets`, `profile-fields` (needs `manage_users`), `notifications`, `onboarding`. Only action: `save` (per section, field `settings_section`).

## Deutsch

### Unterseiten

| Menüpunkt | Route | Capability | View |
|---|---|---|---|
| Übersicht | `/admin/member-dashboard` | `manage_settings` oder `manage_users` | `views/member/dashboard.php` |
| Allgemein | `/admin/member-dashboard-general` | `manage_settings` | `general.php` |
| Design & Farben | `/admin/member-dashboard-design` | `manage_settings` | `design.php` |
| Frontend-Module | `/admin/member-dashboard-frontend-modules` | `manage_settings` | `frontend-modules.php` |
| Dashboard-Widgets | `/admin/member-dashboard-widgets` | `manage_settings` | `widgets.php` |
| Plugin-Widgets | `/admin/member-dashboard-plugin-widgets` | `manage_settings` | `plugin-widgets.php` |
| Profil-Felder | `/admin/member-dashboard-profile-fields` | `manage_users` | `profile-fields.php` |
| Benachrichtigungen | `/admin/member-dashboard-notifications` | `manage_settings` | `notifications.php` |
| Mitglieder-Onboarding | `/admin/member-dashboard-onboarding` | `manage_settings` | `onboarding.php` |

Die Navigation zwischen den Unterseiten liefert `views/member/subnav.php`. Alte Links wie `/admin/member-dashboard?section=design` werden auf die Unterseite umgeleitet.

### Allgemein

| Feld | Option | Hinweis |
|---|---|---|
| Dashboard aktiv | `member_dashboard_enabled` | Deaktiviert die Dashboard-Startseite: `/member` leitet dann auf `/member/profile` um (wirkt nur, wenn das Core-Modul „Mitglieder-Dashboard“ aktiv ist) |
| Logo | `member_dashboard_logo` | Medien-URL |
| Begrüßungszeile | `member_dashboard_greeting` | Standard „Guten Tag, {name}!“ – `{name}` wird durch den Namen des Mitglieds ersetzt |
| Willkommenstext / anzeigen | `member_dashboard_welcome_text`, `member_dashboard_show_welcome` | Kopfbereich des Dashboards |
| Willkommensnachricht | `member_welcome_message` | Nachricht für neue Mitglieder |

### Design & Farben

`member_dashboard_color_primary`, `_accent`, `_bg` (Standard `#f1f5f9`), `_card_bg` (`#ffffff`), `_text` (`#1e293b`), `_border` (`#e2e8f0`). Die Werte werden im Member-Layout als CSS-Variablen ausgegeben.

### Frontend-Module

Ein-/Ausblenden der Dashboard-Bereiche (alle Standard **an**): Schnellstart (`member_dashboard_show_quickstart`), Statistiken (`_show_stats`), eigene Widgets (`_show_custom_widgets`), Plugin-Widgets (`_show_plugin_widgets`), Benachrichtigungs-Panel (`_show_notifications_panel`), Onboarding-Panel (`_show_onboarding_panel`).

### Dashboard-Widgets

- Spaltenanzahl (`member_dashboard_columns`), aktive Standard-Widgets und deren Reihenfolge (`member_dashboard_widgets`).
- **Bereichsreihenfolge** (`member_dashboard_section_order`): z. B. „Statistiken → Widgets → Plugins“ oder „Schnellstart → Statistiken → Plugins → Widgets“.
- Bis zu **vier eigene Widgets** mit Titel, Inhalt und Icon (`member_widget_1_title` … `member_widget_4_icon`), sortierbar (`member_dashboard_custom_widget_order`).

### Plugin-Widgets

Plugins registrieren Dashboard-Kacheln über `CMS\Member\PluginDashboardRegistry` (siehe [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)). Hier lassen sich je Plugin Sichtbarkeit, Titel, Beschreibung, Icon und Farbe überschreiben sowie die Reihenfolge festlegen (`member_dashboard_plugin_order`).

### Profil-Felder

- Standardfelder: Vorname, Nachname, Benutzername, Geburtsdatum, E-Mail, Website, Social-Links, Biografie (Block-Editor), Telefon, Firma, Position, Standort, Profilbild.
- `username` und `email` sind immer aktiv und Pflicht.
- Weitere Pflichtfelder (`member_required_profile_fields`) und **eigene Felder** (`member_custom_profile_fields`: Schlüssel, Label, Typ, Beschreibung, Pflicht, aktiv).
- „Onboarding erneut auslösen“ fordert Mitglieder mit unvollständigem Profil zum Ergänzen auf.
- `member_subscription_visible` blendet den Abo-Bereich im Profil ein.

### Benachrichtigungen

| Feld | Option | Standard |
|---|---|---|
| Benachrichtigungszentrale | `member_dashboard_notification_center_enabled` | an |
| E-Mail-Benachrichtigungen | `member_dashboard_notification_email_enabled` | aus |
| Digest | `member_dashboard_notification_digest_frequency` | `daily` (`instant`, `daily`, `weekly`) |
| Absendername | `member_dashboard_notification_sender_name` | „365CMS Member Hub“ |
| Leertext | `member_dashboard_notification_empty_text` | „Aktuell gibt es keine neuen Meldungen.“ |
| Typen | `member_dashboard_notification_types` | `system`, `messages` (weitere: `billing`, `security`, `community`) |

### Onboarding

Titel, Einleitung, Schritte (je Zeile ein Schritt), CTA-Label und -URL, Option „Profil muss vollständig sein“ (`member_dashboard_onboarding_*`). Das Onboarding-Panel erscheint, bis das Mitglied alle Schritte abgeschlossen hat.

### Technik

- Jede Unterseite postet `action=save` mit `settings_section=<abschnitt>`; `MemberDashboardModule::saveSection()` prüft die Abschnitts-Capability, normalisiert die Werte (Farben als Hex, URLs, Allowlists) und schreibt nur Schlüssel aus `SETTINGS_KEYS`.
- Fehler werden über den Logger-Kanal protokolliert (`member.dashboard.*`), die Seite bleibt bedienbar.
- Laufzeit-Lesezugriff des Member-Bereichs: `MemberDashboardModule::getRuntimeSettings()` bzw. `CMS/member/includes/class-member-controller.php`.

### Verwandte Dokumente

[../../member/README.md](../../member/README.md) · [../../member/MEMBER-DASHBOARD.md](../../member/MEMBER-DASHBOARD.md) · [../users-groups/AUTH-SETTINGS.md](../users-groups/AUTH-SETTINGS.md)
