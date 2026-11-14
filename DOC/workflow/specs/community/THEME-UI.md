# 365CMS – Projektdokumentation | Abschnitt: Community – Theme, Oberfläche & SEO
> **Stand:** 2026-11-14 | **Ziel-Version:** 4.1 (Vorschlag) | **Status:** Planung | **Übersicht:** [README.md](README.md)

## English (summary)

Front-end design of the Community module: core templates under `CMS/views/community/` that any theme can override
(`themes/<slug>/community/<name>.php`, same mechanism as the feed aggregator), the component list, the editor UI,
mobile-first and accessibility rules (WCAG 2.2 AA), design tokens and dark mode, SEO (canonical URLs, pagination,
noindex rules, sitemap, Schema.org `DiscussionForumPosting`/`QAPage`), performance budgets, and the concrete changes
needed in the `cms-phinit` theme, which today redirects `/member/forum` to the plugin and shows a forum quick link
only when `cms-forum` is active.

## Deutsch

### 1. Grundsätze

- **Core liefert vollständige Vorlagen**, die in jedem Theme funktionieren (wie Downloads, Feeds, Formulare). Themes passen über Design-Tokens an und können einzelne Vorlagen überschreiben.
- **Mobile-First**, ab 320 px ohne waagerechtes Scrollen; Touch-Ziele ≥ 44 × 44 px.
- **Kein Inline-JavaScript**, Skripte als Module mit `defer`; ohne JavaScript bleiben Lesen, Antworten (Vollseite), Melden (Vollseite) und Moderation (Formulare) möglich.
- **Design-Tokens** des aktiven Themes (Farben, Radius, Schrift) über CSS-Variablen `--community-*` mit Rückfall auf `--accent`, `--ink`, `--surface` usw. (gleiches Muster wie `forms-public.css`).

### 2. Vorlagen und Overrides

Suchreihenfolge: `themes/<aktiv>/community/<name>.php` → `CMS/views/community/<name>.php`.

| Vorlage | Inhalt |
|---|---|
| `index.php` | Startseite: Kategorien, Foren-Karten/Zeilen, Statistik, Wer ist online |
| `forum.php` | Kopf (Titel, Beschreibung, Moderatoren, Abo-Knopf), Unterforen, Filter, Themenliste, Seitennavigation |
| `topic.php` | Titel, Präfix, Status-Badges, Lösungskasten, Umfrage, Beiträge, Seitennavigation, Schnellantwort, Moderationsmenü |
| `compose.php` | Neues Thema / Antworten / Bearbeiten (Editor, Anhänge, Umfrage, Vorschau) |
| `search.php` | Suchformular mit Filtern, Treffer |
| `listing.php` | „Neue Beiträge“, „Aktive Themen“, „Unbeantwortet“ |
| `profile-section.php` | Community-Abschnitt im Core-Profil |
| `partials/post.php` | ein Beitrag (`<article>`): Autor-Spalte (Avatar, Name, Rang, Abzeichen, Beitragszahl), Inhalt, Bearbeitungshinweis, Anhänge, Reaktionen, Aktionen, Signatur |
| `partials/topic-row.php`, `partials/forum-row.php`, `partials/breadcrumbs.php`, `partials/pagination.php`, `partials/editor.php`, `partials/report-dialog.php`, `partials/moderation-menu.php`, `partials/empty-state.php`, `partials/notice.php` | wiederverwendbare Teile |

Overrides erhalten dieselben Variablen; jede Vorlage dokumentiert sie im Kopfkommentar (wie `themes/cms-default/contact.php`).

### 3. Komponenten und Zustände

| Komponente | Zustände / Hinweise |
|---|---|
| Themenzeile | ungelesen (fett + Punkt), angepinnt, Ankündigung, geschlossen, gelöst, Umfrage, Anhang, wartet auf Freigabe (nur Autor/Moderation), verschoben (Verweis) |
| Beitrag | normal, eigener, ausgeblendet (Platzhalter mit Grund), gelöscht (Platzhalter), wartet auf Freigabe, Lösung, ignoriertes Mitglied (eingeklappt), Ankersprung mit Hervorhebung |
| Editor | Werkzeugleiste (Fett, Kursiv, Durchgestrichen, Überschrift, Liste, Aufgabe, Zitat, Link, Code, Code-Block mit Sprachwahl, Tabelle, Spoiler, Emoji, Anhang), Tastenkürzel (Strg/⌘+B/I/K, Strg+Enter senden), Tabs „Schreiben/Vorschau“, Zeichenzähler, Entwurfs-Hinweis, Ziehen/Einfügen von Bildern, `@`-Vorschläge |
| Reaktionen | Knopf mit Zähler, Auswahl per Tastatur, Liste „wer“ als Dialog |
| Melden | Dialog (`<dialog>`), Gründe als Radio-Gruppe, Rückmeldung `aria-live` |
| Moderationsmenü | ein Menüknopf je Beitrag/Thema statt vieler Icons (mobil), Bestätigung für destruktive Aktionen, Pflichtgrund |
| Seitennavigation | erste, vorherige, Nummern mit Auslassung, nächste, letzte; „Gehe zu Seite“ bei > 10 Seiten |
| Leere Zustände | „Noch keine Themen – starte das erste“, Suche ohne Treffer mit Tipps |
| Neue Beiträge seit dem Laden | Hinweisleiste „2 neue Antworten – anzeigen“ (Polling, nur sichtbarer Tab) |

### 4. Profil

Kein zweites Profil: Das Core-Profil `/author/user-{id}` bzw. die Mitgliederprofilseite erhält über einen Hook den
Community-Abschnitt (Rang, Abzeichen, Beiträge, Reaktionen, Lösungen, Mitglied seit, letzte Aktivität, letzte Themen
aus lesbaren Foren, Knopf „Nachricht senden“). `/forum/mitglieder/{id}` zeigt dieselben Daten in der
Community-Navigation und setzt `canonical` auf das Core-Profil.

### 5. Barrierefreiheit (WCAG 2.2 AA)

- Überschriften: `h1` Seitentitel, `h2` Kategorien/Beitragsbereich; Beiträge als `<article>` mit `aria-labelledby` (Autor + Zeit).
- Zeitangaben als `<time datetime>` mit relativer Anzeige und absolutem Wert im `title`.
- Alle Aktionen per Tastatur, sichtbarer Fokus (Kontrast ≥ 3:1 in beiden Designs), Dialoge mit Fokusfalle und `Esc`.
- Statusmeldungen (gespeichert, gemeldet, Fehler) über `aria-live="polite"`; Fehler im Editor mit `aria-describedby`.
- Ungelesen-Markierung nicht nur über Farbe (zusätzlich Symbol + Text für Screenreader).
- Code-Blöcke: Kontrast der Highlighting-Farben ≥ 4.5:1, Kopier-Knopf mit Text.
- Captcha: immer mit barrierearmer Alternative ([SECURITY-PRIVACY.md](SECURITY-PRIVACY.md) §3).

### 6. SEO

| Thema | Festlegung |
|---|---|
| Kanonische URL | je Seite eine Form; `?page=1` und falscher Slug → 301 |
| Seitennavigation | `rel="prev"/"next"`, Seite ≥ 2 mit eigener `canonical` |
| `noindex` | Suche, Antwort-/Bearbeitungsseiten, leere Foren (Einstellung), Profile ohne Beiträge, Listen „Neue Beiträge“, interne Foren (zusätzlich nie in der Sitemap) |
| Sitemap | Provider für öffentlich lesbare Foren und Themen (`lastmod = last_post_at`) |
| Titel | Thema: `{Titel} – {Forum} – {Site}` (Format einstellbar), Seite ≥ 2 mit „Seite n“ |
| Beschreibung | erster Beitrag gekürzt (ohne Markdown, ohne Code) |
| Schema.org | `DiscussionForumPosting` (Thema) mit `comment` (Antworten, erste Seite), `interactionStatistic`; Q&A-Foren mit gelöstem Thema: `QAPage` mit `acceptedAnswer` |
| Open Graph | Titel, Beschreibung, erstes Bild aus Anhängen (nur öffentliche Foren) |
| Links | Nutzerlinks `rel="nofollow ugc"` |

### 7. Änderungen im Theme `cms-phinit`

Gefunden am 14.11.2026 im Repository `PS-PhinIT/365CMS.DE-PhinIT_THEME`:

| Stelle | Heute | Änderung |
|---|---|---|
| `member/forum.php` | leitet bei aktivem `cms-forum` auf `/member/plugin/forum` um, sonst Hinweis „Plugin nicht aktiviert“ | auf `/member/plugin/community` umleiten, wenn das Core-Modul aktiv ist; Plugin-Weg nur noch als Rückfall |
| `member/partials/member-nav.php` | Eintrag „Forum“ → `/member/plugin/forum` | Eintrag aus der Core-Registry (`community`) übernehmen, Plugin-Slug `plugin_forum` als Alias |
| `member/dashboard.php` | Schnelllink „Forum“ nur bei aktivem `cms-forum` | Bedingung: Core-Modul `community` aktiv **oder** Plugin aktiv; Ziel `/forum` |
| `includes/theme-head-trait.php` | Titel/Brotkrumen für `/member/forum`, `/member/plugin/forum`, Pfad `/forum` | `/member/plugin/community` ergänzen; `/forum`-Erkennung für Assets prüfen (lädt `forms.css`/`rich-content.css` passend?) |
| Assets | keine Community-Styles | `assets/css/community.css` im Theme nur für Abweichungen; Grundstil kommt aus dem Core |
| Lesezeit / Formularseiten | Theme blendet Lesezeit auf Formularseiten aus | Community-Seiten zeigen nie eine Lesezeit (eigene Vorlagen, kein `page-header-block`) |
| Tests | `template-regressions.php` (`member-forum-redirect`, `member-forum-fallback`) | um das Core-Modul erweitern |

Die Theme-Anpassung ist ein eigenes Arbeitspaket (WP-C7 in [ROADMAP.md](ROADMAP.md)) und erscheint als PR im Theme-Repository.

### 8. Leistungsbudget

| Seite | Budget |
|---|---|
| Startseite, Forum, Thema (Gast, warmer Cache) | TTFB ≤ 200 ms, ≤ 6 SQL-Abfragen, CSS ≤ 25 KB (gzip), JS ≤ 30 KB (gzip, ohne Editor) |
| Editor | lädt erst bei Fokus/Antworten (≤ 60 KB gzip inkl. Markdown-Werkzeugleiste, ohne Highlighting) |
| Highlighting | serverseitig (0 KB JS) oder `highlight.js` nur mit benötigten Sprachen (E5) |
| Lighthouse mobil | ≥ 90 in allen vier Kategorien (Startseite, Forum, Thema) |
