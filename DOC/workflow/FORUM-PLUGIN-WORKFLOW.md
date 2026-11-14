# 365CMS – Projektdokumentation | Abschnitt: Workflow – Forum-Plugin
> **Stand:** 2026-11-14 | **Version:** 4.0.0 | **Status:** Abgelöst durch die Planung des Community-Moduls | **Update:** 2026-11-14
> **Nachfolger:** [specs/community/README.md](specs/community/README.md)

## English (summary)

This page used to describe how a forum plugin could integrate with 365CMS 3.5.0 and stated that no forum plugin
existed. Both are outdated: the plugin `cms-forum` (v3.0.4) exists in the separate repository
`PS-PhinIT/365CMS.DE-PLUGINS`, and the forum is now planned as a **Community core module** that replaces the
plugin with data migration. See [specs/community/](specs/community/README.md).

## Deutsch

Diese Seite beschrieb bis 4.0.0 einen Integrationsweg für ein Forum-**Plugin** und ging davon aus, dass es kein
Forum-Plugin gibt. Beides ist überholt:

- Das Plugin **`cms-forum`** (Version 3.0.4, 17 Tabellen `{prefix}cmsforum_*`, Routen unter `/forum`) liegt im
  getrennten Repository `PS-PhinIT/365CMS.DE-PLUGINS`; der Core kennt den Slug-Alias `cms-365netforum` → `cms-forum`.
- Das Forum wird als **Core-Modul „Community“** geplant, das `cms-forum` mit Datenübernahme ablöst – nach demselben
  Muster wie Feed, Downloads, Formulare, Wissensdatenbank und Newsletter in 4.0.0.

| Thema | Dokument |
|---|---|
| Übersicht, Entscheidungen | [specs/community/README.md](specs/community/README.md) |
| Funktionen mit Akzeptanzkriterien | [specs/community/FEATURES.md](specs/community/FEATURES.md) |
| Architektur, Routen, Hooks | [specs/community/ARCHITECTURE.md](specs/community/ARCHITECTURE.md) |
| Datenmodell | [specs/community/DATA-MODEL.md](specs/community/DATA-MODEL.md) |
| Rechte & Moderation | [specs/community/PERMISSIONS-MODERATION.md](specs/community/PERMISSIONS-MODERATION.md) |
| Abläufe | [specs/community/WORKFLOWS.md](specs/community/WORKFLOWS.md) |
| Sicherheit & Datenschutz | [specs/community/SECURITY-PRIVACY.md](specs/community/SECURITY-PRIVACY.md) |
| Theme, Oberfläche, SEO | [specs/community/THEME-UI.md](specs/community/THEME-UI.md) |
| Übernahme aus cms-forum | [specs/community/MIGRATION-CMS-FORUM.md](specs/community/MIGRATION-CMS-FORUM.md) |
| Roadmap & Fortschritt | [specs/community/ROADMAP.md](specs/community/ROADMAP.md) |
| Testplan | [specs/community/TEST-PLAN.md](specs/community/TEST-PLAN.md) |

## Verwandte Dokumente

- [specs/PLUGIN-INTEGRATION.md](specs/PLUGIN-INTEGRATION.md) · [specs/PLUGIN-PARITY.md](specs/PLUGIN-PARITY.md) · [specs/KLARSTELLUNGEN.md](specs/KLARSTELLUNGEN.md)
