# 365CMS – Admin: Knowledgebase

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

The knowledge base (formerly the plugin cms-knowledgebase) is part of the core since 4.0.0. It is managed under *Content → Knowledgebase* (`/admin/knowledgebase`, capability `manage_knowledgebase`). Entries define a focus term and synonyms that are linked automatically in posts and pages (with an optional tooltip), and have eight structured content sections edited with Editor.js. Public pages: `/kb`, `/kb/<slug>`, `/glossar`, `/glossar-sitemap.xml` (also under `/en/…`). Deleting an entry moves it to the trash. Data of the former plugin stays in its tables (`kb_entries`, `kb_categories`, `kb_settings`); older plugin schemas are upgraded automatically.

## Deutsch

### Bereiche

| Bereich | Adresse | Inhalt |
|---|---|---|
| Übersicht | `/admin/knowledgebase` | Kennzahlen (Einträge, aktiv, Kategorien, mit Tooltip), zuletzt geänderte Einträge, Status von Auto-Verlinkung, Tooltips, Menüpunkt und Output-Buffer-Fallback, Links auf die öffentlichen Seiten und den Papierkorb |
| Einträge | `?section=entries` | Suche (Titel, Fokusbegriff, Synonyme, Kurzbeschreibung; ähnlich klingende Begriffe), Filter Kategorie und Status, 25 je Seite; „In den Papierkorb“ je Zeile |
| Eintrag | `?section=edit[&id=<id>]` | Basisdaten (Titel, Fokusbegriff, Slug, Kategorie, Kurzbeschreibung, Tooltip-Text, Synonyme), acht Inhaltsbereiche mit Block-Editor, Status und Verlinkung (aktiv, nur ganze Wörter, Groß-/Kleinschreibung, Priorität, max. Links pro Seite) |
| Kategorien | `?section=categories[&edit=<id>]` | Anlegen, Umbenennen (Einträge ziehen mit), Sortieren, Löschen (Einträge bleiben ohne Kategorie) |
| Einstellungen | `?section=settings&tab=general\|design\|import\|system` | siehe unten |

Ohne ID legt der Editor einen neuen Eintrag an. Schlägt das Speichern fehl (z. B. ohne Fokusbegriff), bleiben die Eingaben im Formular erhalten.

### Inhaltsbereiche

Allgemeine Infos · Funktionsweise & technische Details · Vorteile & Nutzen · Praxisbeispiel & Einsatz · Hinweise & Best Practices · Lizenz & Verfügbarkeit · Fazit · Weiterführende Links. Leere Bereiche erscheinen öffentlich nicht; Shortcodes wie `[site-table id="1"]` werden aufgelöst. Die Inline-Formatierungsleiste (fett, kursiv, Link, Unterstreichen, Markieren, Durchstreichen, Code) ist in den Bereichen aktiv.

### Einstellungen

| Tab | Inhalt |
|---|---|
| Allgemein | Auto-Verlinkung, Fallback für Themes ohne Filter `content_render`, `nofollow`, neuer Tab, Tooltips, max. Links pro Seite gesamt; Menüpunkt in der Hauptnavigation mit Text DE/EN; Titel und Einleitung von `/kb` und `/glossar` DE/EN; Suchfeld, Kategorien-Seitenleiste, Fokusbegriffe auf Karten, verwandte Einträge, Zahl verwandter Beiträge |
| Design | Inhalts- und Seitenleistenbreite, Eckenradius, Farben (Akzent, Hover, Fläche, Rahmen, Tooltip), Übersichtsseite (Kopfbereich, Spalten, Karten-Stil, Kurztext-Zeilen, Karten-Hintergrund, Akzentleiste), Eintragsseite (Kopfbereich, Bereichsdarstellung und -hintergrund, Einfärbung, Icons, Seitenleiste: Stil, Position, Hintergrund, mitlaufend); Vorschau aktualisiert sich beim Ändern |
| Import | Mitgelieferte CSV-Pakete (`core/Services/Knowledgebase/csv/*_Glossar.csv`) einzeln oder alle importieren; Abgleich über den Slug, im Editor ergänzte Inhalte bleiben erhalten. Beispiel-CSV mit allen Feldern herunterladen (`?download=csv-template`) |
| System | Schema-Version, Adressen, letzter CSV-Import; Hardreset (alle Einträge endgültig löschen, auch im Papierkorb) wahlweise mit anschließendem Neuimport aller Pakete – jeweils mit Rückfrage |

Je Formular werden nur die Felder des jeweiligen Tabs gespeichert.

### Papierkorb, Suche, Import/Export

- **Papierkorb:** Löschen setzt `trashed_at`; der Eintrag verschwindet aus `/kb`, Glossar, Sitemap, Auto-Verlinkung und Suche. Wiederherstellen und endgültiges Löschen unter *Papierkorb* (`/admin/trash?type=kb_entry`); die allgemeine Löschfrist des Papierkorbs gilt.
- **Suche:** Aktive Einträge stehen im Suchindex (Typ `kb_entry`, DE `/kb/<slug>`, EN `/en/kb/<slug>`). Speichern, Papierkorb und Löschen aktualisieren den Index sofort; CSV-Importe und der Hardreset ebenfalls. Der stündliche Abgleich erfasst zusätzlich Änderungen ohne Hook (z. B. Kategorie umbenannt).
- **Import/Export:** Typ „Knowledgebase-Eintrag“ unter *System → Import/Export*; Kategorien werden beim Import bei Bedarf angelegt.

### Öffentliche Seiten und Theme-Overrides

`/kb` (Übersicht mit Suche und Kategorien), `/kb/<slug>` (Eintrag mit Bereichen, verwandten Einträgen und Beiträgen, JSON-LD), `/glossar` (A–Z), `/glossar-sitemap.xml`; jeweils auch unter `/en/…`. Vorlagen `views/knowledgebase/archive.php` und `single.php`; ein Theme überschreibt sie mit `themes/<theme>/knowledgebase/<vorlage>.php`. Stylesheets und Tooltip-Skript werden nur auf Seiten geladen, die sie brauchen.

### Hooks

`cms_knowledgebase_entry_saved` (auch nach Wiederherstellen), `cms_knowledgebase_entry_trashed`, `cms_knowledgebase_entry_deleted` – jeweils mit der Eintrags-ID. Siehe [HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md).

### Übernahme aus dem Plugin

Die Tabellen des Plugins werden weiterverwendet. `KnowledgebasePluginMigrator` bringt ältere Plugin-Stände auf das aktuelle Schema (Inhaltsbereiche, Papierkorb-Spalten, Aufteilung von Alt-HTML in Bereiche, fehlende Standardwerte, Kategorien aus den Einträgen); vorhandene Einstellungen bleiben. Das Plugin wird stillgelegt, alte Adressen `/admin/plugins/knowledgebase-dashboard/…` leiten auf die passenden Bereiche um.

### Rechte

`manage_knowledgebase` (Rollen-Gruppe „Knowledgebase“). Ohne dieses Recht ist weder Menüpunkt noch Seite erreichbar.
