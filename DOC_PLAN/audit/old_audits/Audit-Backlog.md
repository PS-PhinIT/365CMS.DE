# 365CMS – Audit-Backlog

Stand: 2026-04-11  

> **⏱️ Historischer Audit-Snapshot (April 2026).** Aktuelle First-Party-Code-Audits (Stand 3.3.47, 2026-06-10): [../AUDIT_core_2026-06-10.md](../AUDIT_core_2026-06-10.md) · [../AUDIT_admin_2026-06-10.md](../AUDIT_admin_2026-06-10.md) · [../AUDIT_member-includes-views_2026-06-10.md](../AUDIT_member-includes-views_2026-06-10.md).
Zweck: Konsolidierter Prüf- und Priorisierungsrahmen als Nachfolger von `PRÜFUNG.MD` sowie der früheren Admin-/Asset-Indizes.

## Konsolidierungsrahmen

- Die frühere Verteilung auf `AdminAudit-*.md`, `AssetAudit-*.md`, `PRÜFUNG.MD`, Snyk-/Live-Reports und Vorschau-Dateien wurde auf **sechs Sammel-Audits** reduziert.
- Historische Bewertungsdeltas bleiben in `BEWERTUNG.md` erhalten.
- Das inkrementelle Arbeitsprotokoll bleibt in `ToDoPrüfung.md` erhalten.
- Diese Datei steuert nur noch **Priorität, Reihenfolge und Rest-Backlog** – nicht mehr die komplette Verlaufshistorie jeder Einzelkorrektur.

## Sammelstruktur der Audit-Dokumentation

| Sammeldatei | Übernommene Altdateien | Schwerpunkt |
|---|---|---|
| `Audit-Content-Platform.md` | `AdminAudit-Dashboard.md`, `AdminAudit-Seiten.md`, `AdminAudit-Beitraege.md`, `AdminAudit-SEO.md`, `AdminAudit-Kommentare.md`, `AssetAudit-EditorJS.md`, `AssetAudit-Beitraege.md`, `AssetAudit-SEO.md`, `AssetAudit-Kommentare.md` | Content, Editor.js, SEO, Listen-/Edit-Flows, Kommentar-Moderation |
| `Audit-Users-Commerce.md` | `AdminAudit-Benutzer.md`, `AdminAudit-Gruppen.md`, `AdminAudit-Member.md`, `AdminAudit-Abos.md`, `AssetAudit-Benutzer.md`, `AssetAudit-Gruppen.md`, `AssetAudit-Member.md`, `AssetAudit-Abos.md` | Benutzer, Gruppen/RBAC, Member-Runtime, Pakete/Bestellungen |
| `Audit-Design-Media.md` | `AdminAudit-Medien.md`, `AdminAudit-Themes.md`, `AdminAudit-Design.md`, `AdminAudit-Hub.md`, `AdminAudit-Tabellen.md`, `AssetAudit-Medien.md`, `AssetAudit-Design.md`, `AssetAudit-Hub.md`, `AssetAudit-Tabellen.md`, `AssetAudit-Grid.md`, `AssetAudit-Themes.md`, `AssetAudit-Marketplace.md` | Medien, Themes, Design, Hub, Tabellen, Grid- und Theme-/Marketplace-Assets |
| `Audit-System-Security.md` | `AdminAudit-Sicherheit.md`, `AdminAudit-Performance.md`, `AdminAudit-Recht.md`, `AdminAudit-Plugins.md`, `AdminAudit-System.md`, `AdminAudit-Info.md`, `AdminAudit-Diagnose.md`, `AssetAudit-Performance.md`, `AssetAudit-Recht.md` | Security, Legal, Performance, Plugins, System, Diagnose, Doku-Sync |
| `Audit-Live-External.md` | `Snyk_Audit_04042026.md`, `LiveAudit_365CMS.md`, `PHINIT-LIVE-AUDIT-2026-04-03.md`, `PHINIT-LIVE-LOCALIZATION-2026-04-03.md`, `PHINIT-LIVE-SEARCH-ARCHIVE-2026-04-03.md` | Snyk-Snapshot, Live-Sites, Produktionsbefunde |
| `Audit-Backlog.md` | `PRÜFUNG.MD`, `AdminAudit-INDEX.md`, `AssetAudit-INDEX.md`, `_admin_pruefung_preview.md` | Priorisierung, Reihenfolge, Restarbeiten, Steuerung |

## Aktueller Arbeitsstand

- Bewertungsstand: siehe `BEWERTUNG.md`
- Letzter dokumentierter Folge-Batch: **Batch 592**
- Letzter konsolidierter Release-Stand: **2.9.190** vor dieser Strukturzusammenführung
- Die operativen Admin-/Asset-Fixes bis einschließlich Design-/Themes-/Abos-/Member-/Medien-Nachzug sind bereits in den Sammel-Audits berücksichtigt.

## Priorisierte Rest-Hotspots

| Priorität | Domäne | Maßgebliche Rest-Hotspots |
|---|---|---|
| kritisch | Content & Shared Assets | `assets/js/admin-content-editor.js`, `assets/js/admin-seo-editor.js`, große Content-/SEO-Editor-Pfade, Restkomplexität in `PostsModule` |
| kritisch | Design / Themes / Marketplace | `CMS/admin/theme-editor.php`, `CMS/admin/modules/themes/ThemeEditorModule.php`, `CMS/admin/theme-marketplace.php`, `CMS/admin/modules/themes/ThemeMarketplaceModule.php` |
| kritisch | System / Security / Remote Flows | `CMS/admin/plugin-marketplace.php`, `CMS/admin/modules/plugins/PluginMarketplaceModule.php`, `CMS/admin/modules/system/BackupsModule.php`, `Documentation*Sync*`, `SettingsModule`, `SeoSuiteModule`, `PerformanceModule` |
| kritisch | Member / Commerce | `member/includes/class-member-controller.php`, Checkout-/Order-Pfade, tiefe Member-/Abo-Runtime-Sonderfälle |
| hoch | Medien / Dateien | `CMS/admin/media.php`, `CMS/admin/modules/media/MediaModule.php`, Upload-/Dateisystem-/Metadaten-Verträge |
| hoch | Tabellen / Hub / Landing | `CMS/admin/modules/tables/TablesModule.php`, `HubTemplateProfileManager.php`, `LandingPageModule.php`, große Admin-Views mit komplexem Zustandsmodell |

## Konsolidierte Reihenfolge für Folge-Runden

1. **Kritische Remote- und Dateisystempfade** weiter schließen  
   Theme-/Plugin-Marketplace, Updates, Backups, Doku-Sync, Theme-Editor, Medien
2. **Große Shared-Assets** weiter zerlegen  
   Editor.js-Bridge, SEO-Editor, Media-/Hub-/Member-JavaScript
3. **Große Orchestrator-Module** weiter staffeln  
   Settings, SEO, Performance, Landing, Member, Tabellen, Hub
4. **Restliche UI-/ViewModel-Verdichtung** pro Domäne nachziehen  
   Vor allem komplexe Edit-Views, Subnav-/Alert-/State-Contracts, Asset-Konfiguration

## Leitplanken für neue Audit-Batches

1. **Runtime vor Doku** – erst echte Verträge härten, dann den Stand in `BEWERTUNG.md` und `ToDoPrüfung.md` spiegeln.
2. **Fail-closed statt kosmetisch** – stale Ziele, parallele Mutationen, Host-Bindung, Direkt-Submit und Remote-Fallbacks bevorzugt schließen.
3. **Shared Contracts bewahren** – relative Admin-/Public-Ziele, native Submitter, strukturierte Logger, DOM-only-Rendering und sichere Datei-/Pfadgrenzen nicht wieder aufweichen.
4. **Sammel-Audits aktuell halten** – neue Findings direkt in die passende Sammeldatei eintragen statt wieder neue Einzel-Audits zu erzeugen.

## Maßgebliche Nachweisdokumente

- `BEWERTUNG.md` – Scores, Deltas, Live-/Snyk-Snapshots
- `ToDoPrüfung.md` – inkrementelles Arbeitsprotokoll
- `Audit-Content-Platform.md`
- `Audit-Users-Commerce.md`
- `Audit-Design-Media.md`
- `Audit-System-Security.md`
- `Audit-Live-External.md`