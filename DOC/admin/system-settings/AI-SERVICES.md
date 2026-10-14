# 365CMS – Projektdokumentation | Abschnitt: Admin – KI-Einstellungen (Verweis)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

AI configuration is not part of *System & Documentation*; it has its own sidebar group **AI Services** with the settings page `/admin/ai-settings`. See [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md) for the admin overview and [../../ai/AI-SERVICES.md](../../ai/AI-SERVICES.md) for the full reference.

## Deutsch

Die KI-Konfiguration liegt in der eigenen Sidebar-Gruppe **KI-Dienste**:

| Aufgabe | Ort |
|---|---|
| Provider, Secrets, Feature-Schalter, Logging, Quotas, Healthcheck | `/admin/ai-settings` |
| Übersicht, Nutzung, letzte Läufe | `/admin/ai-services` |
| Modul ein-/ausschalten | `/admin/modules` → `ai_services` ([MODULES.md](MODULES.md)) |
| Einstellungen-Speicher | `SettingsService`-Gruppen `ai.providers`, `ai.features`, `ai.translation`, `ai.logging`, `ai.quotas`, `ai.prompts`; Quota-Zähler in `cms_ai_quota_usage` |

Weiterführend:

- Admin-Überblick: [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md)
- Vollständige Anwender- und Technikreferenz: [../../ai/AI-SERVICES.md](../../ai/AI-SERVICES.md)
- Assets und Symfony-AI-Platform: [../../ai/AI-ASSETS.md](../../ai/AI-ASSETS.md)
