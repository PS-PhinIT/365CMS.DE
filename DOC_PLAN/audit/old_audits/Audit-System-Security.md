# 365CMS – Audit System, Security & Operations

Stand: 2026-04-11  

> **⏱️ Historischer Audit-Snapshot (April 2026).** Aktuelle First-Party-Code-Audits (Stand 3.3.47, 2026-06-10): [../AUDIT_core_2026-06-10.md](../AUDIT_core_2026-06-10.md) · [../AUDIT_admin_2026-06-10.md](../AUDIT_admin_2026-06-10.md) · [../AUDIT_member-includes-views_2026-06-10.md](../AUDIT_member-includes-views_2026-06-10.md).
Zweck: Konsolidierter Audit-Stand für Security, Legal/Consent, Performance, Plugins, System, Info und Diagnose.

## Übernommene Altdateien

- `AdminAudit-Sicherheit.md`
- `AdminAudit-Performance.md`
- `AdminAudit-Recht.md`
- `AdminAudit-Plugins.md`
- `AdminAudit-System.md`
- `AdminAudit-Info.md`
- `AdminAudit-Diagnose.md`
- `AssetAudit-Performance.md`
- `AssetAudit-Recht.md`

## Konsolidierter Ist-Stand

- Legacy-Entrys und Alias-Routen wurden in weiten Teilen von generischen Admin-Guards auf **explizite Ziel-Capabilities** zurückgeführt.
- Shared Admin-Verträge für Redirects, Flash-Alerts, Post-Action-Shells und relative Admin-/Public-Ziele sind heute deutlich konsistenter als zu Beginn der Audit-Reihe.
- Security-, Legal-, Performance- und Systempfade wurden schrittweise auf **Confirm-Verträge**, **hostneutrale interne Ziele**, **strukturierte Logger/Fehlerpfade** und **kontextnähere Runtime-Wahrheiten** umgestellt.
- Plugin-/Update-/Doku-Sync-/Marketplace-Pfade wurden bereits bei Host-, HTTPS-, Archiv- und Manifest-Zielen enger gezogen, bleiben aber die sensibelste Restfläche dieser Domäne.

## Bereichsstatus

| Scope | Aktueller Schwerpunkt | Gesicherter Stand | Offener Rest-Backlog |
|---|---|---|---|
| Sicherheit | Firewall, AntiSpam, Security-Audit | Capability-Verträge, Confirm-/Cleanup-Pfade, robustere Log-/UI-Kontexte | Weitere Zerlegung der Security-/Firewall-Orchestrierung |
| Recht & Consent | Legal Sites, Cookie-Manager, Datenanfragen | hostneutrale Routen, DOM-basierte Consent-Flows, stärkere Entry-/Generator-Verträge | Generator-/Profil-/CRUD-Module weiter staffeln |
| Performance | Cache, DB, Medien, Sessions, Settings | Teilformular-Verträge, Session-/Cleanup-Runtime, Confirm-Schutz bei Wartungsaktionen | `PerformanceModule` bleibt großer Rest-Hotspot |
| Plugins & Remote | Plugin-Liste, Plugin-Marketplace, Updates | relative Admin-Wechsel, engere Host-/Archiv-/Paketgrenzen, Submit-Locks | kritische Remote- und Installationspfade weiter kapseln |
| System & Updates | Settings, Mail, AI, Backups, Doku-Sync | hostneutrale interne Ziele, reduzierte Schattenwerte, strukturiertere Fehlerpfade | Backups, Settings, Mail und Doku-Sync weiter zerlegen |
| Info & Diagnose | Doku, Support, System-Info, Monitoring, Error-Report | gemeinsame Shells, relative Report-Pfade, mbstring-/Logger-Fallbacks | Weitere Verschlankung großer Diagnose-/Doku-Orchestrierung |

## Maßgebliche Verträge aus den bisherigen Folge-Batches

- **Capability-Vertrag:** Legacy-Entrys sollen dieselben Capabilities wie ihre Zielmodule erzwingen.
- **Error-Report-Vertrag:** Report-Buttons, Back-Links und gespeicherte Source-URLs bleiben same-origin relativ statt an `SITE_URL` gebunden.
- **Remote-Zielvertrag:** Update-/Marketplace-/Doku-Sync-Pfade akzeptieren nur enger validierte HTTPS-/Host-/Port-/Pfad-Kombinationen.
- **Consent-/Legal-Vertrag:** Banner, Modale und Legal-Flows wurden auf DOM-/Shared-Alert-/Confirm-Verträge harmonisiert.

## Restprioritäten in dieser Domäne

| Priorität | Hotspot | Warum noch relevant |
|---|---|---|
| kritisch | `CMS/admin/plugin-marketplace.php` / `CMS/admin/modules/plugins/PluginMarketplaceModule.php` | Remote-Downloads und Paketpromotion bleiben hochsensibel |
| kritisch | `CMS/admin/modules/system/BackupsModule.php` | Dateisystem-/Restore-/Archiv-Risiken und große Operationslogik |
| kritisch | `CMS/admin/modules/system/DocumentationGitSync.php` / `DocumentationGithubZipSync.php` / `DocumentationSyncDownloader.php` | Remote-/Archiv-/Sync-Orchestrierung bleibt tief und risikoreich |
| kritisch | `CMS/admin/modules/settings/SettingsModule.php` | Breite Systemkonfiguration mit mehreren Runtime-Wahrheiten |
| hoch | `CMS/admin/modules/seo/PerformanceModule.php` | Mischdomäne aus Wartung, Sessions, Cache und Settings |
| hoch | `CMS/admin/modules/system/MailSettingsModule.php` / `UpdatesModule.php` | Transport-, Remote- und Updatepfade weiter staffeln |

## Nächste sinnvolle Folge-Richtung

1. Remote- und Archivpfade in Plugin-/Update-/Doku-Sync-/Backup-Logik weiter isolieren.
2. Große Settings-/Performance-/Mail-Orchestratoren in klarere Reader/Writer/Runner zerlegen.
3. Security-, Legal- und Diagnose-Hotspots nur noch in dieser Sammeldatei nachhalten.