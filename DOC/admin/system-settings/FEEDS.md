# 365CMS – Admin: RSS-Feeds

> **Stand:** 2026-11-11 | **Version:** 4.0.0 | **Status:** In Umsetzung

## Deutsch

Die Feed-Einstellungen liegen unter `/admin/settings?tab=feeds` und benötigen die Capability
`manage_feeds`. Der vorhandene öffentliche Endpunkt `/feed` bleibt erhalten.

Unter *Einstellungen → Feeds* lassen sich der Feed aktivieren, die Zahl der Einträge (1–100),
Auszug oder Volltext, Beitragsbilder sowie Kategorie-, Schlagwort- und Sprach-Feeds konfigurieren.
Kategorie- und Schlagwort-Varianten verwenden `/feed?category=<slug>` bzw. `/feed?tag=<slug>`;
Sprachvarianten verwenden `/feed?lang=<locale>`. Bei aktivierter Sprachunterstützung ergänzt das
Standard-Theme `<link rel="alternate">` für die verfügbaren Sprachen.

`ThemeRouter` leitet `/feed` an den vorhandenen `FeedService` weiter. Volltext wird aus dem
gespeicherten Editor.js-Inhalt mit `EditorJsRenderer` erzeugt, durch `PurifierService` bereinigt und
als `content:encoded` ausgegeben; relative Medien- und Linkpfade werden auf die Site-URL bezogen.
Kategorie-Feeds berücksichtigen auch zusätzliche Zuordnungen und Unterkategorien. Beitragsbilder
werden als Media-RSS-Bildreferenz ausgegeben. RSS-Antworten unterstützen ETag und Last-Modified mit
`304 Not Modified`. Der Cache läuft nach fünf Minuten ab und wird bei Änderungen an Beiträgen,
Kategorien (einschließlich Zuordnungsrelation), Schlagwörtern und Feed-Einstellungen verworfen.

Die Einstellungen werden in `cms_settings` unter der Gruppe `feeds` gespeichert. Änderungen
erfordern CSRF und werden im Audit-Log protokolliert. Es wird keine Site-Zuordnung für Inhalte
eingeführt.

## English

RSS settings are available at `/admin/settings?tab=feeds` and require `manage_feeds`. The existing
public `/feed` URL remains unchanged. Administrators can enable the feed, choose 1–100 entries,
excerpt or full-text output, featured images, category and tag variants, and language variants.
Responses support ETag and Last-Modified conditional requests; the five-minute cache is invalidated
when posts, taxonomies, or feed settings change.
