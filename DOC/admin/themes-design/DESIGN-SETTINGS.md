# 365CMS – Projektdokumentation | Abschnitt: Admin – Design-Einstellungen (Alt-Route)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Legacy-Weiterleitung

## English (summary)

`/admin/design-settings` no longer has its own screen. `CMS/admin/design-settings.php` is a redirect alias (`partials/redirect-alias-shell.php`) to **`/admin/theme-editor`**, the customizer of the active theme. The former `CMS/admin/modules/themes/DesignSettingsModule.php` and `CMS/admin/views/themes/settings.php` were no longer referenced and were removed in 3.4.13. Theme-independent site settings moved to `/admin/settings` (`/admin/theme-settings` redirects there).

## Deutsch

### Aktueller Stand

| Alte Route | Verhalten | Neuer Ort |
|---|---|---|
| `/admin/design-settings` | Weiterleitung (Capability `manage_settings`) | `/admin/theme-editor` – Farben, Layout, Header, Footer im Customizer des aktiven Themes ([CUSTOMIZER.md](CUSTOMIZER.md)) |
| `/admin/theme-settings` | Weiterleitung | `/admin/settings` – Website-Name, Logo, Favicon, Sprache usw. ([../system-settings/SYSTEM.md](../system-settings/SYSTEM.md)) |
| `/admin/theme-customizer` | Weiterleitung durch `AdminRouter` | `/admin/theme-editor` |

### Nicht mehr verdrahteter Code

Das in 3.4.13 entfernte `DesignSettingsModule` verwaltete früher theme-unabhängige Werte (`color_primary` `#2563eb`, `color_secondary`, `color_accent`, `color_text`, `color_bg`, `color_bg_dark`, `layout_container_width` 960–1920, `layout_sidebar_position`, `layout_border_radius`, `header_sticky`, `header_transparent`, `header_search`, `footer_columns`, `footer_dark`, `perf_lazy_loading`, `perf_minify_css`, `perf_minify_js`, `custom_css`). Diese Optionen können in Bestandsdatenbanken noch vorhanden sein, werden vom mitgelieferten Theme aber nicht ausgewertet. Neue Themes sollten ihre Gestaltungsoptionen über einen eigenen Customizer abbilden.

### Verwandte Dokumente

[CUSTOMIZER.md](CUSTOMIZER.md) · [FONTS.md](FONTS.md) · [README.md](README.md)
