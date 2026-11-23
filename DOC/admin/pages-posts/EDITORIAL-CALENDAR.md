# 365CMS Projektdokumentation | Admin: Redaktionskalender

> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Stable
> **Route:** `/admin/editorial-calendar` | **Capability:** `view_editorial_calendar` | **CSRF-Aktion:** `admin_editorial_calendar` | **JSON-Endpunkt:** `/admin/editorial-calendar-api`

## English (summary)

The editorial calendar (`CMS/admin/editorial-calendar.php` → `modules/content/EditorialCalendarModule.php` → `Services\EditorialCalendarService`) shows posts and pages in a month and week view: scheduled posts (`published_at` in the future), planned drafts (`planned_at`, editorial only, publishes nothing), optionally published content and, since 4.1.0, **review dates** (`review_due_at`, own colour, display only, not movable). Items can be moved by drag and drop through `/admin/editorial-calendar-api`. Submitted content (`pending`, four-eyes review) is shown like a draft. The validity fields **expiry date** (`expires_at`) and **review date** (`review_due_at`) of posts, pages and knowledge-base entries are part of the publication panel; the hourly cron demotes or marks expired content, the daily cron reminds the author about due reviews.

## Deutsch

### Ansichten und Einträge

Monats- und Wochenansicht (höchstens 62 Tage je Abfrage, höchstens 500 Einträge; wird gekürzt, weist die Seite darauf hin). Die Zeiten werden in der Zeitzone der Website angezeigt, gespeichert wird in der Serverzeit. Filter: Autor, Typ (Beitrag/Seite), Kategorie, Sprache und „Veröffentlichte anzeigen“.

| Art (`kind`) | Quelle | Verschiebbar |
|---|---|---|
| `scheduled` (geplant) | Beiträge mit `status = published` und `published_at` in der Zukunft | ja: neuer Termin; liegt er nicht mehr in der Zukunft, wird nur nach ausdrücklicher Bestätigung („jetzt veröffentlichen“) sofort veröffentlicht |
| `planned` (Planung) | Entwürfe und eingereichte Inhalte (`pending`) mit `planned_at` | ja: setzt nur das Planungsdatum, veröffentlicht nichts |
| `published` | veröffentlichte Beiträge und Seiten (nur mit Filter) | nein |
| `review` (Wiedervorlage) | Beiträge und Seiten mit `review_due_at`, eigene Farbe | nein (nur Anzeige) |

Beim Verschieben auf einen veröffentlichten Beitrag gilt die Veröffentlichungs-Checkliste (Beitragsbild, Kategorie, Meta-Beschreibung). Das Recht zum Veröffentlichen (`posts.publish`) und das Vier-Augen-Prinzip ([REVIEW.md](REVIEW.md)) gelten auch hier.

### Gültigkeit: Ablaufdatum und Wiedervorlage

Im Panel *Veröffentlichung* von Beitrags- und Seiteneditor und in der Seitenspalte des Wissensdatenbank-Editors steht die Gruppe „Gültigkeit“ (Partial `views/partials/content-expiry-fields.php`):

- **Ablaufdatum** (`expires_at`): Der stündliche Cron stuft abgelaufene, veröffentlichte Inhalte auf *Entwurf* (Wissensdatenbank: inaktiv) zurück oder markiert sie nur. Einstellung *Einstellungen → Beiträge & Sites → Bei Ablauf* (`setting_content_expiry_action` = `draft` | `mark`). Das Frontend antwortet danach mit 404 oder, Einstellung *Antwort für zurückgestufte Inhalte* (`setting_content_expiry_response`), mit **410 Gone** (nur Beiträge und Seiten). Der Autor erhält einen Hinweis; Hook `cms_content_expired`.
- **Wiedervorlage** (`review_due_at`): keine Statusänderung. Der tägliche Cron meldet fällige Inhalte einmal an den Autor (Hook `cms_content_review_due`); „Als geprüft markieren“ setzt den nächsten Termin (Intervall `setting_content_review_interval` in Monaten, Standard 12).
- Die Spalten `expiry_notified_at` und `review_notified_at` verhindern Mehrfachmeldungen. Je Lauf werden höchstens 100 Inhalte je Typ verarbeitet.
- Das Dashboard zeigt die Karte „Fällige Prüfungen“, der Kalender die Wiedervorlagen.

Details und Seitenbezug: [POSTS.md](POSTS.md#gültigkeit-ablauf-und-wiedervorlage), [PAGES.md](PAGES.md), [KNOWLEDGEBASE.md](KNOWLEDGEBASE.md).

### Berechtigung

`view_editorial_calendar` (Standard: Editor; Admin immer, siehe [../users-groups/RBAC.md](../users-groups/RBAC.md)). Verschieben prüft zusätzlich das Bearbeitungsrecht des jeweiligen Inhalts.

### Verwandte Dokumente

[README.md](README.md) · [POSTS.md](POSTS.md) · [PAGES.md](PAGES.md) · [REVIEW.md](REVIEW.md) · [../../core/DATABASE-SCHEMA.md](../../core/DATABASE-SCHEMA.md)
