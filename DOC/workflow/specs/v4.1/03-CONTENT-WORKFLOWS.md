# 365CMS – Projektdokumentation | Abschnitt: Spec 4.1.0 – Bereich 3: Redaktions- & Content-Workflows
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1.0 · Schema v26 | **Status:** Spezifikation | **Übersicht:** [README.md](README.md)

## English (summary)

(3a) Four-eyes review: status `pending` for posts and pages, enforced server-side for users without
`posts.publish`/`pages.publish`, review list, dashboard widget and notifications – this adopts part A of
[ROADMAP-CONTENT-PLATFORM.md](../ROADMAP-CONTENT-PLATFORM.md) unchanged and only fixes details. (3b) Reusable Editor.js
blocks managed under `/admin/reusable-blocks` and embedded by reference through a `reusableBlock` tool registered in
`EditorJsBlockRegistry`, rendered live with recursion protection. (3c) Expiry and review dates for posts, pages and
knowledge-base entries with a cron job that unpublishes expired content and flags due reviews in the editorial
calendar.

## Deutsch

### 3a. Vier-Augen-Prinzip (`pending`)

Übernimmt **Teil A** aus [ROADMAP-CONTENT-PLATFORM.md](../ROADMAP-CONTENT-PLATFORM.md) (Ist-Stand, Ziel, Datenmodell
`content_workflow_events` und `content_preview_links`, Dienste `ContentStatus`/`ContentWorkflow`, Migration der
Rechte). Festlegungen für 4.1.0:

- Der Auftrag nennt `publish_posts`; im Core heißen die Rechte **`posts.publish`** und **`pages.publish`** (bestehen in `RolesModule`, werden heute nicht geprüft). Es wird keine zweite Capability eingeführt.
- Update-Schritt vergibt `posts.publish` an `editor` und `author`, `pages.publish` an `editor` (heutiges Verhalten bleibt); die Einstellung „Autoren brauchen eine Freigabe“ entzieht es `author`.
- **Dashboard-Widget** „Ausstehende Prüfungen“ (`DashboardService`, nur mit Veröffentlichungsrecht): Titel, Autor, eingereicht am, Link.
- **Benachrichtigung:** Mail (Mail-Warteschlange) und In-App-Hinweis an alle Konten mit Veröffentlichungsrecht für den Typ; Rückgabe/Freigabe an den Autor; Badge `content_pending` im Admin-Menü.
- **Webhook-Ereignis** `content.submitted` (Bereich 4b).
- Tests: `TESTS/content-workflow/run.php` – Durchsetzung im Speichern (auch über `post-editor-api.php`), Statusübergänge, Rechte-Migration, Benachrichtigungsempfänger, Vorschau-Link (signiert, Ablauf, widerrufbar).

### 3b. Wiederverwendbare Editor.js-Blöcke (Synced Snippets)

| Punkt | Festlegung |
|---|---|
| Verwaltung | `/admin/reusable-blocks` (Liste, Anlegen, Bearbeiten im Editor.js, „Verwendet in“, Duplizieren, Löschen mit Warnung), Capability `blocks.reusable` |
| Speicherung | eigene Tabelle (unten); Inhalt als Editor.js-JSON, je Sprache (`content`, `content_en`) |
| Einbindung | Editor.js-Tool `reusableBlock` über `EditorJsBlockRegistry::register('reusableBlock', …)` – Registrierung in Factory, Asset-Registry **und** Renderer (DEVLIST-Fallstrick „alle drei Stellen“); Block-Daten nur `{ "id": 12 }` |
| Darstellung | Renderer lädt den aktuellen Block und rendert dessen Blöcke mit dem normalen `EditorJsRenderer` + Purifier; **Rekursionsschutz** (Block in sich selbst, Tiefe > 3 → leer + Admin-Hinweis); fehlender/gelöschter Block → im Frontend nichts, im Editor Hinweis |
| Editor | Auswahl-Dialog (Suche), Vorschau serverseitig über `post-editor-api.php` (`block_preview`), Knopf „Bearbeiten“ öffnet den Block in neuem Tab, „In normale Blöcke umwandeln“ (Kopie, Verknüpfung endet) |
| Cache | Ändern eines Blocks leert den Cache aller verknüpften Inhalte (Index aus `reusable_block_usage`) |
| Suche/Feeds | Suchindex und RSS rendern Blöcke aufgelöst (Inhalt erscheint in jedem verknüpften Beitrag) |
| Revisionen | Änderungen am Block erzeugen keine Revision der verknüpften Beiträge; eigener einfacher Verlauf (letzte 20 Fassungen) |

```sql
CREATE TABLE {p}reusable_blocks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(150) NOT NULL,
  content LONGTEXT NOT NULL, content_en LONGTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',   -- active | trash
  created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL
);
CREATE TABLE {p}reusable_block_usage (
  block_id INT UNSIGNED NOT NULL, content_type VARCHAR(30) NOT NULL, content_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (block_id, content_type, content_id), KEY idx_content (content_type, content_id)
);
CREATE TABLE {p}reusable_block_revisions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, block_id INT UNSIGNED NOT NULL,
  content LONGTEXT NOT NULL, content_en LONGTEXT NULL, created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL,
  KEY idx_block (block_id, created_at)
);
```

Tests (`TESTS/reusable-blocks/run.php`): Rendern mit aktuellem Inhalt, Sprache, Rekursion (direkt und über zwei Ebenen),
gelöschter Block, Sanitizing der Block-Daten (nur `id`), Nutzungsindex nach Speichern, Cache-Invalidierung.

### 3c. Ablauf- und Prüfdaten (Content-Expiration & Wiedervorlage)

| Punkt | Festlegung |
|---|---|
| Felder | `expires_at` (Ablauf) und `review_due_at` (Wiedervorlage) an Beiträgen, Seiten, Wissensdatenbank-Einträgen; Seitenleiste im Editor „Gültigkeit“ |
| Aktion bei Ablauf | Einstellung je Inhaltsart: auf `draft` zurückstufen (Standard) oder nur markieren; `previous_status` wird gemerkt; Hinweis an Autor + Redaktion; Hook `cms_content_expired` |
| Wiedervorlage | keine Statusänderung; Markierung im Redaktionskalender (`/admin/editorial-calendar`, eigene Farbe), Widget „Fällige Prüfungen“, Mail an Autor; Hook `cms_content_review_due`; „Geprüft“-Knopf setzt neues Datum (+ Intervall aus Einstellung, z. B. 12 Monate) |
| Cron | `hourly`: abgelaufene Inhalte verarbeiten (Batch 100, idempotent über `expires_at <= NOW() AND status = 'published'`) |
| Frontend | abgelaufene, nur markierte Inhalte bleiben sichtbar; zurückgestufte liefern 404 bzw. 410 (Einstellung) |
| SEO | Sitemap entfernt zurückgestufte Inhalte sofort (Cache-Invalidierung) |

```sql
ALTER TABLE {p}posts ADD COLUMN expires_at DATETIME NULL, ADD COLUMN review_due_at DATETIME NULL,
  ADD INDEX idx_expires (status, expires_at), ADD INDEX idx_review_due (review_due_at);
ALTER TABLE {p}pages ADD COLUMN expires_at DATETIME NULL, ADD COLUMN review_due_at DATETIME NULL,
  ADD INDEX idx_expires (status, expires_at), ADD INDEX idx_review_due (review_due_at);
ALTER TABLE {p}kb_entries ADD COLUMN expires_at DATETIME NULL, ADD COLUMN review_due_at DATETIME NULL,
  ADD INDEX idx_review_due (review_due_at);
```

Tests (`TESTS/content-expiry/run.php`): Cron stuft genau die fälligen zurück, zweiter Lauf ändert nichts, „nur markieren“,
Kalender-Einträge, Wiedervorlage-Intervall, Zeitzonen (Eingabe in Site-Zeitzone, Speicherung UTC).
