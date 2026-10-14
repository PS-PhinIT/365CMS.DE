# 365CMS – Projektdokumentation | Abschnitt: Admin – KI-Dienste (AI Services)

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Routen:** `/admin/ai-services`, `/admin/ai-translation`, `/admin/ai-content-creator`, `/admin/ai-seo-creator`, `/admin/ai-settings` | **Core-Modul:** `ai_services` | **CSRF-Aktion:** `admin_ai_services`

## English (summary)

The sidebar group **AI Services** (*KI-Dienste*) bundles five admin screens sharing one controller, `CMS/admin/ai-page.php` (sections `overview`, `translation`, `content_creator`, `seo_creator`, `settings`), with `CMS/admin/modules/system/AiServicesModule.php` and the view `CMS/admin/views/system/ai-services.php`. Read access: administrator plus `manage_settings`, `manage_system` or `manage_ai_services`; write access: `manage_settings`. Two protected JSON endpoints serve the page/post editor: `POST /admin/ai-translate-editorjs` and `POST /admin/ai-generate-seo-metadata`. There are no public AI routes and AI output is never published automatically.

The complete user and technical documentation (providers, policies, quotas, contracts, prompts, security) is in **[../../ai/AI-SERVICES.md](../../ai/AI-SERVICES.md)**.

## Deutsch

### Seiten

| Menüpunkt | Route | Bereich | Aktionen |
|---|---|---|---|
| KI-Dashboard | `/admin/ai-services` | `overview` | – (Status, Nutzung, Quotas, letzte Läufe) |
| Übersetzung | `/admin/ai-translation` | `translation` | `save_translation`, `save_translation_prompts` |
| Inhaltsassistent | `/admin/ai-content-creator` | `content_creator` | `save_content_prompts`, `generate_content_draft` |
| SEO-Assistent | `/admin/ai-seo-creator` | `seo_creator` | `save_seo_prompts` |
| Einstellungen | `/admin/ai-settings` | `settings` | `save_providers`, `delete_provider`, `save_features`, `save_logging`, `save_quotas`, `check_provider_health` |

Aktionen werden nur im passenden Bereich akzeptiert; unbekannte Aktionen werden abgelehnt.

### Berechtigungen

| Zweck | Capability |
|---|---|
| Seiten ansehen | `manage_settings`, `manage_system` oder `manage_ai_services` |
| Einstellungen ändern | `manage_settings` |
| Editor-Übersetzung | `manage_ai_services`, `manage_settings`, `use_ai_translation` oder Bearbeitungsrecht (`manage_pages` / `edit_all_posts`) |
| SEO-Metadaten im Editor | `manage_ai_services`, `manage_settings`, `use_ai_seo_meta` oder Bearbeitungsrecht |

Wie der gesamte Adminbereich setzen alle Seiten die Rolle `admin` voraus ([../users-groups/RBAC.md](../users-groups/RBAC.md)).

### Provider

Unterstützte Typen: `mock` (lokal, Standard), `openai`, `mistral`, `openrouter` (OpenAI-kompatibel), `azure_openai`, `ollama` (selbst gehostet). Pro Provider: Endpunkt, Modell bzw. Deployment, API-Version, Secret (verschlüsselt), freigegebene Funktionen (Übersetzung, Umschreiben, Zusammenfassung, SEO, Editor.js), erlaubte Sprachen, Profil/Beta, erlaubte interne Hosts (Ollama). Ein Fallback-Provider springt nur bei vorübergehenden Fehlern ein.

**Schutzmechanismen:** Cloud-Endpunkte nur über HTTPS, Ollama nur auf exakt freigegebenen internen Hosts, Datenweitergabe an externe Provider muss ausdrücklich aktiviert sein (`ai_external_provider_data_sharing_enabled`), atomare Quotas (Tabelle `cms_ai_quota_usage`), Retry max. 2, keine Speicherung von Rohprompts oder Volltexten im Log.

### Einbindung im Editor

- **Übersetzen:** Button „Mit AI nach EN übersetzen“ im Seiten-/Beitragseditor; blockweise Requests, Vorschau/Diff vor Übernahme.
- **SEO-Metadaten:** erzeugt Meta-Titel, -Beschreibung, Fokus-Keyphrase, Social-Texte u. a. aus dem Inhalt; nur sichtbar, wenn `ai_services_enabled`, `ai_seo_meta_enabled` und `ai_editorjs_enabled` aktiv sind und der Provider die Editor-Sprache erlaubt.
- Ergebnisse sind immer Vorschläge; gespeichert wird erst mit dem normalen Speichern.

### Typische Einrichtung

1. `/admin/modules`: Modul `ai_services` aktiv.
2. `/admin/ai-settings`: Provider anlegen, Secret hinterlegen, Funktionen und Sprachen freigeben, `check_provider_health`.
3. Globale Feature-Schalter setzen, Quotas festlegen, Logging-Modus wählen.
4. Prompt-Vorlagen unter Übersetzung / Inhaltsassistent / SEO-Assistent anpassen (optional).
5. Im Editor testen und Ergebnisse redaktionell prüfen.

### Verwandte Dokumente

[../../ai/AI-SERVICES.md](../../ai/AI-SERVICES.md) (vollständige Referenz) · [../../ai/AI-ASSETS.md](../../ai/AI-ASSETS.md) · [../system-settings/MODULES.md](../system-settings/MODULES.md) · [../pages-posts/PAGES.md](../pages-posts/PAGES.md)
