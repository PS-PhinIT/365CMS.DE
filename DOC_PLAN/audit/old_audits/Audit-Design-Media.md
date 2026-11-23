# 365CMS – Audit Design, Media & UX-Assets

Stand: 2026-04-11  

> **⏱️ Historischer Audit-Snapshot (April 2026).** Aktuelle First-Party-Code-Audits (Stand 3.3.47, 2026-06-10): [../AUDIT_core_2026-06-10.md](../AUDIT_core_2026-06-10.md) · [../AUDIT_admin_2026-06-10.md](../AUDIT_admin_2026-06-10.md) · [../AUDIT_member-includes-views_2026-06-10.md](../AUDIT_member-includes-views_2026-06-10.md).
Zweck: Konsolidierter Audit-Stand für Medien, Themes, Design-Admin, Hub, Tabellen sowie die dazugehörigen Asset-Verträge.

## Übernommene Altdateien

- `AdminAudit-Medien.md`
- `AdminAudit-Themes.md`
- `AdminAudit-Design.md`
- `AdminAudit-Hub.md`
- `AdminAudit-Tabellen.md`
- `AssetAudit-Medien.md`
- `AssetAudit-Design.md`
- `AssetAudit-Hub.md`
- `AssetAudit-Tabellen.md`
- `AssetAudit-Grid.md`
- `AssetAudit-Themes.md`
- `AssetAudit-Marketplace.md`

## Konsolidierter Ist-Stand

- Medienpfade wurden gegen stale Browse-Ziele, Protected Paths, falsche Root-Breadcrumbs, Upload-/Move-Zielordnerfehler und CSRF-Token-Rollover deutlich gehärtet.
- Theme- und Design-Bereiche arbeiten heute wesentlich enger an **managed roots**, **symlink-sicheren Dateioperationen**, **atomischen Saves mit Integritätsprüfung**, **readonly-Binärgrenzen** und **nativen Submitter-Fallbacks**.
- Theme-Explorer, Menü-Editor, Font-Manager, Hub-Assets und Tabellen-Assets wurden schrittweise von Inline-/String- und Direkt-Submit-Sonderpfaden auf DOM-/Confirm-/Submit-Lock-Verträge umgestellt.

## Bereichsstatus

| Scope | Aktueller Schwerpunkt | Gesicherter Stand | Offener Rest-Backlog |
|---|---|---|---|
| Medien | Bibliothek, Upload, Kategorien, Settings, Move/Rename/Delete | stale Pfade, Protected Paths, Root-Breadcrumb, Token-Rollover, native Select/Delete-Roundtrips | `MediaModule`, Dateisystem- und ViewModel-Komplexität weiter abbauen |
| Themes | Theme-Liste, Aktivierung, Delete, Marketplace | dediziertes `admin-themes.js`, managed roots, Locking, symlink-sicheres Delete und Install | Theme-/Marketplace-Orchestrierung bleibt hochkritisch |
| Design | Theme-Editor, Explorer, Font-Manager, Loginpage, Landing | atomische Theme-Saves, readonly-Binärschutz, Font-Asset-Integrität, logger- und mbstring-saubere Servicepfade | Theme-Editor-/Landing-/Font-Module weiter zerlegen |
| Hub | Site-/Template-Editoren, Public-/Clipboard-Verträge | hostneutrale Public-Pfade, DOM-only-Restpfade, korrekte Alerts, ausgelagerte Assets | große Edit-/Template-Module weiter staffeln |
| Tabellen & Grid | Search-Bridge, Hidden-Dispatch, Editor-JSON, Grid-Basis | hostneutrale Roundtrips, DOM-only-Rebuilds, JSON-/stale-Edit-Guards | `TablesModule` und Editor-UI weiter entkoppeln |

## Maßgebliche Verträge aus den bisherigen Folge-Batches

- **Datei-Integritätsvertrag:** Theme-Explorer- und Font-Manager-Saves laufen atomisch und fail-closed.
- **Managed-Root-Vertrag:** Theme-Discovery, Theme-Delete und Marketplace-Finalisierung bleiben innerhalb validierter Theme-Wurzeln.
- **Medienvertrag:** stale Pfade und nicht existente Ziele werden früh verworfen; Upload-Clients übernehmen Token-Rollover auch im Fehlerpfad.
- **Asset-Vertrag:** Theme-Explorer, Menü-Editor und Font-Manager nutzen auch in Legacy-Fallbacks native Submitter statt direkter `form.submit()`.

## Restprioritäten in dieser Domäne

| Priorität | Hotspot | Warum noch relevant |
|---|---|---|
| kritisch | `CMS/admin/theme-editor.php` / `CMS/admin/modules/themes/ThemeEditorModule.php` | Dateisystem-, Save-, Explorer- und Fallback-Komplexität bleibt hoch |
| kritisch | `CMS/admin/theme-marketplace.php` / `CMS/admin/modules/themes/ThemeMarketplaceModule.php` | Remote-/Archiv-/Zielpfade bleiben sensibler Installationspfad |
| hoch | `CMS/admin/modules/media/MediaModule.php` | Großer Mix aus Browse-, Upload-, Bulk-, Kategorie- und Settings-Logik |
| hoch | `CMS/admin/modules/tables/TablesModule.php` | Editor-/Listen-/Settings-Logik stark verdichtet |
| hoch | `CMS/admin/modules/hub/HubTemplateProfileManager.php` | Hohe Template-/Sync-/Profile-Komplexität |
| hoch | `CMS/admin/modules/landing/LandingPageModule.php` | Mischverantwortung aus Header, Content, Features, Plugins und Design |

## Nächste sinnvolle Folge-Richtung

1. Theme- und Marketplace-Remote-/Dateisystempfade weiter kapseln.
2. Medien- und Tabellenmodule in kleinere Reader/Writer/Builder aufteilen.
3. Design-/Hub-/Landing-Views und Assets weiter von Großzuständen entlasten.