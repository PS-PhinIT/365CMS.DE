# 365CMS – Admin: Letzte Änderungen

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

*Content → Recent changes* (`/admin/content-changes`) lists every change to posts and pages in one timeline: created,
updated, status changed, revision restored and autosaves. Filters: type, user, kind of change and date range (50 per
page). Each row links to the editor and to the revision comparison. Rights: `edit_all_posts` (posts) and/or
`manage_pages` (pages); only the types you may edit are shown. No site filter, hub sites are not included.

## Deutsch

### Aufbau

| Spalte | Inhalt |
|---|---|
| Zeitpunkt | Zeitpunkt der Revision bzw. des Anlegens |
| Inhalt | Titel mit Typ (Beitrag/Seite); Klick öffnet den Editor |
| Änderung | *Angelegt*, *Bearbeitet*, *Status geändert* (z. B. Entwurf → Veröffentlicht), *Wiederhergestellt*, *Autosave* |
| Benutzer | wer gespeichert hat |
| Aktion | *Vergleichen* öffnet den Editor mit dem Revisionsvergleich (`?revision=<id>`) |

Filter: Typ, Benutzer, Art der Änderung, Zeitraum (von/bis). 50 Einträge je Seite, neueste zuerst.

### Datenquelle

`CMS\Services\ContentChangesService` liest die vorhandenen Revisionen, es gibt keine eigene Protokolltabelle:

- Beiträge: `post_revisions` (Statuswechsel per Vergleich mit der vorherigen Revision),
- Seiten: `page_revisions`; seit 4.0.0 mit Spalte `created_by` (Schema v25), ältere Seitenrevisionen zeigen
  „unbekannt“,
- Anlegen: `created_at` der Beiträge und Seiten.

Inhalte im Papierkorb erscheinen weiterhin mit ihrer Historie. Keine Site-Dimension, Hub-Sites sind nicht enthalten
([KLARSTELLUNGEN.md](../../workflow/specs/KLARSTELLUNGEN.md)).

### Rechte

`edit_all_posts` zeigt Beiträge, `manage_pages` Seiten. Ohne eines der beiden Rechte ist die Seite nicht erreichbar.

### Dateien

`CMS/admin/content-changes.php`, `CMS/admin/views/content/changes.php`, `CMS/core/Services/ContentChangesService.php`
