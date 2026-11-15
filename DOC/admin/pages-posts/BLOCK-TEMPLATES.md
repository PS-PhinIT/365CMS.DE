# 365CMS – Projektdokumentation | Abschnitt: Admin – EditorJS Block Vorlagen

> **Stand:** 2026-11-15 | **Version:** 4.1.0 | **Status:** Stable
> **Route:** `/admin/reusable-blocks` | **Capability:** `blocks.reusable` | **CSRF-Aktion:** `admin_reusable_blocks` | **Editor.js-Block:** „Vorlagen“ (`reusableBlock`)

## English (summary)

*EditorJS Block Vorlagen* are reusable Editor.js content snippets ("synced snippets"). They are managed at `/admin/reusable-blocks` (list with search and usage count, editor with German and optional English content, "Verwendet in", history of the last 20 versions with restore, duplicate, delete with warning) and embedded in posts and pages through the Editor.js block **"Vorlagen"** (`reusableBlock`). The block stores only a reference (`{ "id": 12 }`); the frontend always renders the current template in the request language (English falls back to German). Changing a template clears the page cache and re-runs the save hooks of every linked post and page (search index, sitemap), including content that uses the template through another template. Recursion is rejected on save (self-inclusion, cycles across both languages) and stopped while rendering (repetition or depth > 3: nothing in the frontend, a notice in the editor preview). Deleted templates render nothing; the editor shows a notice. "In normale Blöcke umwandeln" replaces the reference with a copy of the template's blocks. Search indexing, RSS and media usage see the template content. Service: `CMS\Services\ReusableBlocks\ReusableBlockService`; tables `cms_reusable_blocks`, `cms_reusable_block_usage`, `cms_reusable_block_revisions`.

## Deutsch

### Wozu?

Inhalte, die an vielen Stellen gleich aussehen sollen – Kontakt-Box, Hinweis zur Barrierefreiheit, Aktionsbanner, Autorenbox – pflegst du **einmal** als Vorlage und bindest sie überall über den Editor.js-Block **„Vorlagen“** ein. Änderst du die Vorlage, zeigen alle Beiträge und Seiten sofort den neuen Inhalt.

### Verwalten (`/admin/reusable-blocks`)

Menü: *Bausteine → EditorJS Block Vorlagen* (Profil „Klassisch“) bzw. *Medien → EditorJS Block Vorlagen* (Profil „Strukturiert“).

| Bereich | Inhalt |
|---|---|
| Liste | Titel, Sprachen (DE bzw. DE, EN), „Verwendet in“ (Anzahl), Änderungsdatum, Duplizieren; Suche nach Titel |
| Bearbeiten | Titel (nur intern), Inhalt **Deutsch** und optional **English** im normalen Block-Editor – ohne englische Fassung erscheint auf englischen Seiten die deutsche |
| Verwendet in | Beiträge, Seiten und andere Vorlagen, die diese Vorlage einbinden, mit Link in den Editor |
| Verlauf | Bei jedem Speichern wird der vorherige Stand gesichert, die letzten 20 Fassungen bleiben; „Wiederherstellen“ macht den aktuellen Stand selbst wieder zur Fassung |
| Duplizieren | Kopie mit dem Titel „… (Kopie)“, ohne Verlauf und ohne Verwendungen |
| Löschen | Bestätigung immer nötig; wird die Vorlage verwendet, nennt die Rückfrage die Zahl der Stellen. Danach erscheint dort im Frontend nichts, der Editor zeigt einen Hinweis |

### Einbinden im Beitrag oder auf der Seite

1. Im Editor den Block **„Vorlagen“** einfügen – im Beitragseditor über die Block-Auswahl (*Layout & Spezial*), im Seiteneditor über Plus-Menü oder Schnellleiste.
2. Vorlage aus der Liste wählen – darunter erscheint die Vorschau mit dem aktuellen Inhalt.
3. Speichern. Im Beitrag steht nur der Verweis `{ "id": … }`.

Im Block gibt es außerdem:

- **Vorlage bearbeiten** – öffnet die Vorlage in einem neuen Tab (mit `blocks.reusable`).
- **In normale Blöcke umwandeln** – ersetzt den Verweis durch eine Kopie der Blöcke in der Sprache des Editors. Die Verknüpfung endet; spätere Änderungen an der Vorlage erscheinen hier nicht mehr.

### Was nach einer Änderung passiert

- Der Seiten-Cache wird geleert.
- Für jeden Beitrag und jede Seite, die die Vorlage – auch über eine andere Vorlage – einbinden, laufen die normalen Speicher-Hooks (`cms_after_post_save`/`cms_after_page_save`): Suchindex, Sitemap und weitere Folgeaktionen sehen den neuen Inhalt. Die Inhalte selbst und ihre Revisionen bleiben unverändert.
- Suche und Feeds lösen die Vorlage auf (`SiteSearchService::registerBlockTextResolver()`), der Verwendungsnachweis der Medien zählt Bilder in Vorlagen bei der Vorlage.

### Schutz vor Endlosschleifen (Zyklenprüfung)

- Speichern wird abgelehnt, wenn eine Vorlage sich selbst einbindet – direkt oder über andere Vorlagen, in beiden Sprachfassungen.
- Beim Rendern bricht der Dienst zusätzlich ab, wenn eine Vorlage erneut vorkommt oder mehr als 3 Ebenen verschachtelt sind (z. B. nach einem Import): Frontend nichts, Editor-Vorschau ein Hinweis, Protokoll-Eintrag im Kanal `content.reusable_blocks`.

### Rechte

| Aktion | Recht |
|---|---|
| Vorlagen verwalten | `blocks.reusable` (Admin; Redakteur in den Standardrollen) |
| Block „Vorlagen“ im Editor nutzen | wer den Beitrag bzw. die Seite bearbeiten darf |

### Technik

| Baustein | Aufgabe |
|---|---|
| `ReusableBlocks\ReusableBlockService` | Anlegen/Ändern mit Verlauf, Duplizieren, Löschen, Nutzungsindex, verknüpfte Inhalte, Rendern mit Rekursionsschutz, Suchtext, Umwandeln |
| `ReusableBlocks\ReusableBlock` | Editor.js-Block: Sanitizer (nur `id`) und Renderer |
| `ReusableBlocks\ReusableBlockMediaExtractor` | Verwendungsnachweis der Medien |
| `FeatureHooks\ReusableBlockHooks` | Registrierung in `EditorJsBlockRegistry`, Nutzungsindex beim Speichern/Löschen, Suchtext |
| `assets/js/editor-blocks/reusable-block.js` | Editor-Werkzeug „Vorlagen“ |
| `/admin/post-editor-api` | `reusable_block_choices` (Auswahl), `reusable_block_content` (Umwandeln), `block_preview` (Vorschau) |
| Hooks | `cms_reusable_block_saved`, `cms_reusable_block_deleted`, `cms_reusable_block_changed` ([HOOKS-REFERENCE.md](../../core/HOOKS-REFERENCE.md)) |
| Tests | `php TESTS/reusable-blocks/run.php` |

### Verwandte Dokumente

[POSTS.md](POSTS.md) · [PAGES.md](PAGES.md) · [../../core/DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md) · [Spezifikation 3b](../../workflow/specs/v4.1/03-CONTENT-WORKFLOWS.md)
