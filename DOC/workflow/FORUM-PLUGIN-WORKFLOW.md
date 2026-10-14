# 365CMS – Projektdokumentation | Abschnitt: Workflow – Forum-Plugin
> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Nicht in der Runtime enthalten | **Update:** 2026-10-02
> **Quellen:** `CMS/plugins/` (nur `cms-importer`), `CMS/core/PluginManager.php` (`PLUGIN_SLUG_ALIASES`), `CMS/marketplace/plugins/index.json`

## English (summary)

No forum plugin ships with 365CMS 3.5.0: `CMS/plugins/` contains only `cms-importer`, and the marketplace catalog lists no forum entry. The core only knows the slug alias `cms-365netforum → cms-forum` in `PluginManager::PLUGIN_SLUG_ALIASES`, so an old active-plugin entry is mapped to `cms-forum`. This document therefore describes the **integration path** a forum plugin must follow, using only core APIs that exist today.

## Deutsch

### Ist-Stand

| Aspekt | Stand |
|---|---|
| Plugin im Repository | nein (`CMS/plugins/` enthält nur `cms-importer`) |
| Marketplace-Eintrag | nein |
| Core-Bezug | Slug-Alias `cms-365netforum` → `cms-forum` |
| Tabellen, Routen, Views | keine im Core |

### Integrationsfahrplan für ein Forum-Plugin `cms-forum`

1. **Grundgerüst:** `CMS/plugins/cms-forum/cms-forum.php` mit Header (`Plugin Name`, `Version`, `Requires CMS: 3.4.00`, `Requires PHP: 8.4`) – siehe [plugins/GUIDE.md](../plugins/GUIDE.md).
2. **Tabellen** in `cms_forum_activate()` mit `CREATE TABLE IF NOT EXISTS {$prefix}forum_…` anlegen (Präfix über `Database::instance()->getPrefix()`); Löschen nur in `cms_forum_uninstall()`.
3. **Öffentliche Routen** im Hook `register_routes` (`/forum`, `/forum/:slug`, `/forum/thread/:id`); Ausgabe über das aktive Theme (`ThemeManager`). POST-Formulare benötigen das globale Token `form_guard`.
4. **Mitgliederfunktionen** (eigene Beiträge, Abos) über die Member-Plugin-Registry (`PluginDashboardRegistry`) und `/member/plugin/<slug>` einbinden – siehe [member/MEMBER-DASHBOARD.md](../member/MEMBER-DASHBOARD.md).
5. **Admin-Moderation** über `add_menu_page()`/`cms_admin_menu`, Capability-Prüfung, CSRF je Aktion.
6. **Spam-/Missbrauchsschutz:** `Security::checkDbRateLimit()`, Inhalte mit `PurifierService` bzw. Escaping ausgeben, keine Roh-HTML-Speicherung ohne Filter.
7. **Benachrichtigungen:** E-Mails über `MailQueueService::getInstance()->enqueue()` (Versand per Cron `mail-queue`), In-App-Hinweise über das Member-Benachrichtigungsmodul.
8. **DSGVO:** Hooks `dsgvo_export_data` und `dsgvo_delete_data` implementieren (Beiträge anonymisieren oder löschen).
9. **Abo-Gating (optional):** `SubscriptionManager::canAccessPlugin($userId, 'forum')`.
10. **Rechtstexte:** Datenschutzerklärung unter *Recht → Rechtstexte-Generator* ergänzen.
11. **Verteilung:** über den Marketplace nach [MARKETPLACE-WORKFLOW.md](MARKETPLACE-WORKFLOW.md).

Nach der Implementierung ist dieses Dokument durch eine Beschreibung der tatsächlichen Routen, Tabellen und Rechte zu ersetzen.

## Verwandte Dokumente

- [plugins/PLUGIN-DEVELOPMENT.md](../plugins/PLUGIN-DEVELOPMENT.md) · [core/HOOKS-REFERENCE.md](../core/HOOKS-REFERENCE.md) · [API-INTEGRATION-WORKFLOW.md](API-INTEGRATION-WORKFLOW.md)
