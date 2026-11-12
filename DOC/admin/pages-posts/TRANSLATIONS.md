# 365CMS – Admin: Übersetzungsstatus

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable

## English (summary)

*Content → Translations* (`/admin/translations`, capability `manage_translations`) shows a matrix of posts and pages
with the status of each content language: **missing**, **current** or **outdated** (the German source changed after
the last sync). Filters: type, language, status, search. Actions per row: open the editor, create the translation
(copy DE → EN, optionally followed by AI translation) and *mark as current*. No site filter, hub sites are not
included.

## Deutsch

### Status

| Status | Bedeutung |
|---|---|
| Fehlt | Titel und Inhalt der Sprache sind leer |
| Aktuell | Die deutsche Fassung ist seit dem letzten Abgleich unverändert |
| Veraltet | Die deutsche Fassung (Titel, Inhalt oder Auszug) wurde nach dem letzten Abgleich geändert |

Verglichen wird ein Fingerabdruck aus Titel, Editor.js-Blöcken (ohne Zeitstempel und Versionsangabe) und Auszug. Die
Abgleiche stehen in der Tabelle `translation_sync` (Schema v25: Typ, ID, Sprache, Fingerabdruck von Quelle und
Übersetzung, Methode, Benutzer, Zeitpunkt).

Ein Abgleich wird gespeichert,

- wenn beim Speichern die Übersetzung selbst geändert wurde (jemand hat sie bearbeitet),
- bei *DE → EN kopieren* im Editor,
- bei *Als aktuell markieren* in der Übersicht,
- einmalig für vorhandene Übersetzungen ohne Eintrag: sie gelten ab Einführung als aktuell.

### Aktionen

| Aktion | Wirkung |
|---|---|
| Bearbeiten | öffnet den Editor in der Sprache |
| Übersetzung anlegen | kopiert die deutsche Fassung nach EN (nur wenn sie fehlt) und öffnet den Editor; mit KI-Recht wird dort die KI-Übersetzung gestartet |
| Als aktuell markieren | übernimmt den aktuellen Stand der Quelle, ohne Text zu ändern |

Alle Aktionen sind per CSRF geschützt und werden im Audit-Log protokolliert.

### Rechte

`manage_translations` für die Seite; zusätzlich `edit_all_posts` (Beiträge) bzw. `manage_pages` (Seiten) für die
angezeigten Typen.

### Dateien

`CMS/admin/translations.php`, `CMS/admin/views/content/translations.php`,
`CMS/core/Services/TranslationStatusService.php`, `CMS/core/Services/FeatureHooks/ContentOverviewHooks.php`
