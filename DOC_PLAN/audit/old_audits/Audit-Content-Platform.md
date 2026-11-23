# 365CMS – Audit Content & Platform

Stand: 2026-04-11  

> **⏱️ Historischer Audit-Snapshot (April 2026).** Aktuelle First-Party-Code-Audits (Stand 3.3.47, 2026-06-10): [../AUDIT_core_2026-06-10.md](../AUDIT_core_2026-06-10.md) · [../AUDIT_admin_2026-06-10.md](../AUDIT_admin_2026-06-10.md) · [../AUDIT_member-includes-views_2026-06-10.md](../AUDIT_member-includes-views_2026-06-10.md).
Zweck: Konsolidierter Audit-Stand für redaktionelle Admin-Pfade, Editor.js, SEO, Kommentar-Moderation und zugehörige Assets.

## Übernommene Altdateien

- `AdminAudit-Dashboard.md`
- `AdminAudit-Seiten.md`
- `AdminAudit-Beitraege.md`
- `AdminAudit-SEO.md`
- `AdminAudit-Kommentare.md`
- `AssetAudit-EditorJS.md`
- `AssetAudit-Beitraege.md`
- `AssetAudit-SEO.md`
- `AssetAudit-Kommentare.md`

## Konsolidierter Ist-Stand

- Dashboard-, Seiten-, Beiträge-, Kommentar- und SEO-Pfade hängen inzwischen wesentlich enger an gemeinsamen Shell-, Alert-, Confirm- und Redirect-Verträgen.
- Editor.js-/Content-Integrationen wurden mehrfach auf **DOM-only-Rendering**, **live synchronisierte Hidden-JSON-Verträge**, **stale-sichere DE→EN-/AI-Entscheidungen** und **native Submitter statt Direkt-`submit()`** nachgezogen.
- SEO-Runtime und Admin-Pfade wurden host- und runtime-aware nachgeschärft; same-site Public-URLs werden nicht mehr unnötig als extern/fremd fehlklassifiziert.
- Kommentar-Moderation und Content-Listen sind in Bulk-/Delete-/Status-Pfaden heute deutlich näher an denselben nativen Browser- und Capability-Verträgen wie der restliche Admin.

## Bereichsstatus

| Scope | Aktueller Schwerpunkt | Gesicherter Stand | Offener Rest-Backlog |
|---|---|---|---|
| Dashboard | KPIs, Attention-Items, Startnavigation | Shared-Shell, hostneutrale Schnellzugriffe und sauberere KPI-Verträge sind dokumentiert | Weitere Voraggregation und ViewModel-Ausdünnung |
| Seiten & Beiträge | Listen, Edit, Taxonomien, Bulk, Editor.js | stale Edit-/Bulk-Ziele, Delete-/Bulk-Guards, Editor.js-Submit- und Sync-Verträge wurden stark nachgezogen | Große Module/Views und Shared-Editor weiter zerlegen |
| Editor.js / Content Assets | DE→EN, AI-Preview/Diff, Hidden-JSON, Media-Bridge | DOM-only-Fallbacks, Live-Sync, fail-closed Save-Serialisierung, native Submitter | `admin-content-editor.js` bleibt kritischer Großbaustein |
| SEO Admin | Dashboard, Audit, Meta, Social, Schema, Sitemap, Redirects, 404 | runtime-aware Public-URLs, Confirm-/Delete-Verträge, Rule-/Score-Rendering ohne HTML-Sonderpfade | `SeoSuiteModule`, `PerformanceModule`, `admin-seo-editor.js` weiter staffeln |
| Kommentare | Status-Tabs, Bulk-Bar, Einzelaktionen | Bulk-Delete-Confirm, request-submit-saubere Dispatches und RBAC-/Moderationspfade stehen | Weitere Builder-/ViewModel-Trennung und Listenverschlankung |

## Maßgebliche Verträge aus den bisherigen Folge-Batches

- **Shared Editor-Vertrag:** Editor.js-Daten werden aktiv zurück in Hidden-Felder gespiegelt; Folge-Assets arbeiten nicht mehr auf stale JSON-Zuständen.
- **AI-/Übersetzungsvertrag:** manuelle EN-Änderungen invalidieren offene AI-Vorschläge; DE→EN-Kopie und AI-Overwrite verlangen den echten aktuellen Zielzustand.
- **SEO-Runtime-Vertrag:** Same-Site-Absolute-URLs gelten über konfigurierte und aktuelle Runtime-Autorität hinweg als intern.
- **Kommentar-Vertrag:** Einzelaktionen und Bulk-Pfade laufen über denselben Confirm-/Submitter-/Capability-Rahmen.

## Restprioritäten in dieser Domäne

| Priorität | Hotspot | Warum noch relevant |
|---|---|---|
| kritisch | `assets/js/admin-content-editor.js` | Zentrale Shared-Bridge für Save-, Sync-, AI- und Preview-Flows; Komplexität und Risikofläche bleiben hoch |
| kritisch | `assets/js/admin-seo-editor.js` | Hohe UI-/Analyse-Komplexität, Rule-Rendering und Live-Auswertung hängen an einem großen Client-Baustein |
| hoch | `CMS/admin/modules/posts/PostsModule.php` | Große gemischte Listen-/Edit-/Taxonomie-/Delete-Verantwortung |
| hoch | `CMS/admin/modules/seo/SeoSuiteModule.php` | Breiter Orchestrator für Settings, Audit, Runtime-Hinweise und Public-Status |
| hoch | `CMS/admin/modules/seo/PerformanceModule.php` | Wartung, Session-, Cache- und Settings-Logik bleiben stark verdichtet |

## Nächste sinnvolle Folge-Richtung

1. Shared Editor-/SEO-Assets weiter in kleinere Verantwortungsblöcke staffeln.
2. Große Content- und SEO-Module weiter in Reader/Writer/Builder aufteilen.
3. Nur noch Sammeldokumentation in dieser Datei fortschreiben – keine neuen Einzel-Audits pro Bereich erzeugen.