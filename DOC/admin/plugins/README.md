# 365CMS – Projektdokumentation | Abschnitt: Admin – Plugins

> **Stand:** 2026-11-09 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

This folder documents plugin management, marketplace and plugin updates (sidebar entry **Plugins** in the group *Verwaltung*). Admin pages registered by plugins appear in the separate sidebar group **Plugins** under `/admin/plugins/<plugin>/<page>`, one entry per plugin; since 4.0.0 the plugin assignment controls per site where they are shown.

## Deutsch

| Menüpunkt | Route | Dokument |
|---|---|---|
| Plugins verwalten | `/admin/plugins` | [PLUGINS.md](PLUGINS.md) |
| Marketplace | `/admin/plugin-marketplace` (nur wenn `marketplace_enabled`) | [MARKETPLACE.md](MARKETPLACE.md) |
| Plugin-Updates | `/admin/updates` | [UPDATES.md](UPDATES.md) |
| Plugin-Adminseiten | `/admin/plugins/<plugin>/<seite>` | [PLUGINS.md](PLUGINS.md#plugin-adminseiten) |
| Plugin-Zuweisung (Einstellungen › Plugins) | `/admin/plugin-assignment` | [PLUGINS.md](PLUGINS.md#plugin-zuweisung) |

Entwicklung eigener Plugins: [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md) · Leitfaden: [../../plugins/GUIDE.md](../../plugins/GUIDE.md) · Marketplace-Paketformat: [../../plugins/PLUGIN-MARKETPLACE.md](../../plugins/PLUGIN-MARKETPLACE.md).
