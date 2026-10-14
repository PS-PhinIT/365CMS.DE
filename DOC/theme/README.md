# 365CMS – Projektdokumentation | Abschnitt: Theme-Entwicklung – Übersicht

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Referenz-Theme:** `cms-default` 1.0.9

## English (summary)

Documentation for building 365CMS themes. Themes live in `CMS/themes/<slug>/` and are rendered by `CMS\ThemeManager`. The shipped reference theme is **Meridian CMS Default** (`cms-default`, version 1.0.9). Start with [THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md) for the contract.

## Deutsch

| Dokument | Inhalt |
|---|---|
| [THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md) | Theme-Vertrag: Aufbau, `theme.json`, Templates und Daten, Hooks, CSP, Datenschutz, Customizer, Versionierung |
| [DEVELOPMENT.md](DEVELOPMENT.md) | Praktischer Ablauf: Kopie von `cms-default`, lokale Entwicklung, Release-Prüfliste, Auslieferung |
| [COMPONENTS.md](COMPONENTS.md) | Templates, Partials und Helfer von `cms-default`, wiederverwendbare Core-Komponenten |
| [DESIGN-SYSTEM.md](DESIGN-SYSTEM.md) | Design-Tokens, Customizer-Zuordnung, Schriften, Admin-UI (Tabler) |
| [JAVASCRIPT.md](JAVASCRIPT.md) | `theme.js`, Core-Frontend-Skripte, CSP-Regeln |

### Admin-Seiten rund ums Theme

Theme-Verwaltung `/admin/themes`, Customizer `/admin/theme-editor`, Dateieditor `/admin/theme-explorer`, Menüs `/admin/menu-editor`, Landingpage `/admin/landing-page`, Schriften `/admin/font-manager` – siehe [../admin/themes-design/README.md](../admin/themes-design/README.md).
