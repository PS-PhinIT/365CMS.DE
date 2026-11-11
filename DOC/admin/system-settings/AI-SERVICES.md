# 365CMS – Projektdokumentation | Abschnitt: Admin – KI-Einstellungen (Verweis)

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

The settings page `/admin/ai-settings` (*KI-Einstellungen*) is a tile in the category *KI*. It sits on the **Einstellungen** tile page in the *Classic* sidebar profile and under **Konfiguration** in *Structured*. The other AI screens form the optional group **KI-Dienste**, which appears only while the core module `ai_services` is active. See [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md) for the admin overview and [../../ai/AI-SERVICES.md](../../ai/AI-SERVICES.md) for the full reference.

## Deutsch

Die Seite **KI-Einstellungen** (`/admin/ai-settings`) ist eine Kachel der Kategorie *KI*: unter *Einstellungen* im Profil *Klassisch*, unter *Konfiguration* im Profil *Strukturiert*. Die übrigen KI-Seiten bilden die optionale Gruppe **KI-Dienste**. Sie erscheint nur bei aktivem Modul `ai_services`. Im Profil *Klassisch* steht sie im Abschnitt *Arbeit* direkt nach *Formulare*, im Profil *Strukturiert* direkt nach *Redaktion*.

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
