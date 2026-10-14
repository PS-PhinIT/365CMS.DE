# 365CMS – Projektdokumentation | Abschnitt: Admin – Hub-Sites

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/hub-sites` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_hub_sites`

## English (summary)

Hub sites are curated landing/overview pages built from cards, links and sections. They are stored as special rows in `cms_site_tables` (`content_mode = hub`) and managed at `/admin/hub-sites` (`CMS/admin/hub-sites.php` → `CMS/admin/modules/hub/HubSitesModule.php`, template profiles in `HubTemplateProfileCatalog.php` / `HubTemplateProfileManager.php`). Rendering is done by `CMS\Services\SiteTableService` and `SiteTable\SiteTableHubRenderer`.

- Views: `list`, `edit`, `templates`, `template-edit`.
- Actions: `save`, `delete`, `duplicate`, `save-template`, `duplicate-template`, `delete-template`.
- Delivery: under `/<hub_slug>`, optionally on a dedicated domain (`hub_domains`), or embedded via shortcode `[hub-site id="…"]`.

## Deutsch

### Überblick

Eine Hub-Site ist eine kuratierte Übersichtsseite (z. B. „Microsoft 365“, „PowerShell“, „Datenschutz“) mit Hero-Bereich, Metadaten-Leiste, Schnelllinks, Abschnitten und Karten. Technisch ist sie ein Datensatz in `cms_site_tables`, dessen `settings_json` den Modus `content_mode = hub` trägt.

| Bestandteil | Datei |
|---|---|
| Einstieg | `CMS/admin/hub-sites.php` |
| Modul | `CMS/admin/modules/hub/HubSitesModule.php` |
| Template-Profile | `HubTemplateProfileCatalog.php`, `HubTemplateProfileManager.php` |
| Views | `CMS/admin/views/hub/list.php`, `edit.php`, `templates.php`, `template-edit.php` (+ `template-edit/main-column.php`, `sidebar-column.php`) |
| Rendering | `CMS/core/Services/SiteTableService.php`, `CMS/core/Services/SiteTable/SiteTableHubRenderer.php`, `SiteTableTemplateRegistry.php`, `SiteTableRepository.php` |

### Ansichten

| URL | Inhalt |
|---|---|
| `/admin/hub-sites` | Liste mit Name, Slug, Anzahl Karten, Änderungsdatum |
| `/admin/hub-sites?action=edit[&id=…]` | Hub-Site anlegen/bearbeiten |
| `/admin/hub-sites?action=templates` | Template-Profile verwalten |
| `/admin/hub-sites?action=template-edit&key=…` | Template-Profil bearbeiten |

### Mitgelieferte Templates

`general-it` (IT Themen Allgemein, Standard), `services` (Dienstleistungen / Landing Hub), `general-table`, `microsoft-365`, `m365-table`, `powershell-table`, `datenschutz`, `compliance`, `datenschutz-compliance-table`, `linux`, `linux-table`. Eigene Profile werden in `cms_settings` unter `hub_site_templates` gespeichert und können dupliziert oder gelöscht werden.

### Felder einer Hub-Site

| Gruppe | Felder (jeweils DE und `_en`) |
|---|---|
| Grunddaten | Name, `hub_slug`, `hub_domains` (eigene Domains), `hub_template` |
| Hero | `hub_badge` (max. 80), `hub_hero_title` (160), `hub_hero_text` (Rich-Text, 4000), `hub_cta_label` (60), `hub_cta_url` |
| Meta-Leiste | `hub_meta_audience`, `hub_meta_owner`, `hub_meta_update_cycle`, `hub_meta_focus`, `hub_meta_kpi` |
| Navigation & Struktur | `hub_links_json` (Schnelllinks), `hub_sections_json` (Abschnitte) |
| Karten | Kartenliste (`rows_json`), `hub_feature_cards_json` + `hub_feature_card_interval` (Feature-Karten zwischen normalen Karten, nur bei unterstützenden Templates) |
| Kartendesign | `hub_card_layout` (`standard`, `feature`, `compact`), `hub_card_image_position` (`top`, `left`, `right`), `hub_card_image_fit` (`cover`, `contain`), `hub_card_image_ratio` (`wide`, `square`, `portrait`), `hub_card_meta_layout` (`split`, `stacked`), Radius, Zeilenabstand, Feature-Bildbreite/-höhe |
| Sonstiges | `hub_show_author_box` |

Leere Design-Felder übernehmen die Werte des gewählten Template-Profils. Mit „Nach dem Speichern öffentlich öffnen“ springt der Editor direkt auf die Live-Seite.

### Auslieferung im Frontend

1. **Slug:** `Router`/`ThemeRouter` lösen `/<hub_slug>` über `SiteTableService::getHubPageBySlug()` auf (DE und EN).
2. **Eigene Domain:** Ist der aufgerufene Host in `hub_domains` eingetragen, liefert `Router` die Hub-Site als Startseite dieser Domain (`getHubPageByDomain()`). Die Domain muss per DNS/Vhost auf die 365CMS-Installation zeigen.
3. **Shortcode:** `[hub-site id="12"]` in Seiten- oder Beitragsinhalt.

### Hinweise

- Hub-Sites erscheinen nicht in der Tabellenliste unter `/admin/site-tables`.
- Beim Löschen einer Hub-Site sollten verweisende Menüs und Weiterleitungen geprüft werden (`/admin/redirect-manager`).

### Verwandte Dokumente

[TABLES.md](TABLES.md) · [PAGES.md](PAGES.md) · [../seo/REDIRECTS.md](../seo/REDIRECTS.md)
