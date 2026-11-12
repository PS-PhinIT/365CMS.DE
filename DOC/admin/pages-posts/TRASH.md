# 365CMS – Admin: Papierkorb

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

Deleted pages, posts, media files, hub sites, downloads, forms and form submissions go to the trash first. The central
page *Tools → Trash* (`/admin/trash`, capability `manage_trash` plus the capability of each content type) lists every
type with counts, search, restore, permanent deletion, “empty trash” per type and the period for automatic emptying
(0 = never). Items that are still in use (e.g. media embedded in content) need an explicit confirmation before they
are deleted permanently. Trashed items are no longer public; restore re-adds them (slug or domain conflicts are
resolved and reported).

## Deutsch

### Was landet im Papierkorb?

| Typ | Löschen in … | Hinweise |
|---|---|---|
| Seiten, Beiträge | Listen unter Seiten bzw. Beiträge (Filter „Papierkorb“) | Vorheriger Status wird gemerkt; Wiederherstellen löst Adress-Konflikte (neue Adresse wird gemeldet) |
| Medien | Mediathek (Einzel- und Sammelaktion) | Datei samt Vorschaubildern wandert in den privaten Speicher `trash/media/`; Ordner werden dateiweise verschoben |
| Hub-Sites | Hub-Sites → Aktionen | Sofort weder per Adresse noch per Domain erreichbar; beim Wiederherstellen inzwischen vergebene Domains werden entfernt und gemeldet |
| Downloads, Formulare, Formulareingänge, Knowledgebase-Einträge (Newsletter-Abonnenten werden bewusst endgültig gelöscht oder abgemeldet) | jeweilige Verwaltung | über die Inhaltstyp-Provider der Bereiche |

Weitere Bereiche erscheinen automatisch, sobald sie einen Papierkorb-Provider registrieren (siehe
[CONTENT-TYPE-PROVIDERS.md](../../core/CONTENT-TYPE-PROVIDERS.md)).

### Zentrale Seite `/admin/trash`

- Reiter je Typ mit Anzahl; Suche, Seitenblättern (50 je Seite).
- Je Eintrag *Wiederherstellen* und *Endgültig löschen*, dazu Sammelaktionen und *Papierkorb leeren* je Typ.
- Einträge, die noch verwendet werden (z. B. Medien in Beiträgen, als Impressum zugeordnete Seiten), werden nicht
  stillschweigend gelöscht: Die Seite listet sie mit den Fundstellen auf und löscht erst nach ausdrücklicher
  Bestätigung.
- **Automatisch leeren:** Frist in Tagen (0 = nie, Standard 30). Der tägliche Cron löscht ältere Einträge endgültig;
  noch verwendete Medien bleiben erhalten. Die Regel erscheint auch unter *Recht → Löschfristen*.

Rechte: `manage_trash` und je Typ die Capability des Bereichs (z. B. `manage_pages`, `manage_media`). Alle Aktionen
werden im Audit-Log protokolliert (`trash.*`, `hub_sites.*`).
