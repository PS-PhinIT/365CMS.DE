# 365CMS – Projektdokumentation | Abschnitt: Admin – Plugins

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

This folder documents plugin management, marketplace and plugin updates. In the sidebar they are in the group *Plugins* (Classic) or *Erweitern* (Structured); *Updates* is a direct link at the top in both profiles. Admin pages registered by plugins (`/admin/plugins/<plugin>/<page>`) appear in that fallback group, in a group of their own or at a preferred place per profile. Since 4.0.0 the plugin assignment controls per site where they are shown.

## Deutsch

In der Seitenleiste stehen diese Seiten in der Gruppe *Plugins* (Profil *Klassisch*) bzw. *Erweitern* (Profil *Strukturiert*). *Updates* ist in beiden Profilen ein direkter Link oben.

| Menüpunkt | Route | Dokument |
|---|---|---|
| Plugins verwalten | `/admin/plugins` | [PLUGINS.md](PLUGINS.md) |
| Plugin-Marktplatz | `/admin/plugin-marketplace` (nur wenn `marketplace_enabled`) | [MARKETPLACE.md](MARKETPLACE.md) |
| Plugin-Updates | `/admin/updates` | [UPDATES.md](UPDATES.md) |
| Plugin-Adminseiten | `/admin/plugins/<plugin>/<seite>` | [PLUGINS.md](PLUGINS.md#plugin-adminseiten) |
| Plugin-Zuweisung (Einstellungen › Plugins) | `/admin/plugin-assignment` | [PLUGINS.md](PLUGINS.md#plugin-zuweisung) |

Entwicklung eigener Plugins: [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md) · Leitfaden: [../../plugins/GUIDE.md](../../plugins/GUIDE.md) · Marketplace-Paketformat: [../../plugins/PLUGIN-MARKETPLACE.md](../../plugins/PLUGIN-MARKETPLACE.md).
