# 365CMS – Projektdokumentation | Abschnitt: Admin – Theme-Customizer

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/theme-editor` (Menü „Theme-Editor“) | **Capability:** `manage_settings` | **CSRF-Aktion (Theme):** `theme_customizer`

## English (summary)

`/admin/theme-editor` embeds the **customizer of the active theme**: `CMS/admin/theme-editor.php` validates and includes `<theme>/admin/customizer.php` inside the admin layout. Values are stored per theme in `cms_theme_customizations` via `CMS\Services\ThemeCustomizer` and turned into CSS variables by `ThemeCustomizer::generateCSS()`. If the theme has no customizer, or the file is unsafe, the fallback view `views/themes/customizer-missing.php` explains why.

## Deutsch

### Ablauf beim Aufruf

1. `theme-editor.php` ermittelt das aktive Theme (`ThemeManager::getActiveThemeSlug()`).
2. Die Datei `<theme>/admin/customizer.php` wird vor dem Einbinden geprüft:
   - muss innerhalb des Theme-Verzeichnisses liegen und lesbar sein,
   - höchstens **256 KB**, keine Binärdaten,
   - gültige PHP-Syntax,
   - keine riskanten Aufrufe (`eval`, `exec`, `system`, `shell_exec`, `passthru`, `proc_open`, `popen`, `base64_decode`).
3. Schlägt eine Prüfung fehl, erscheint `views/themes/customizer-missing.php` mit Ursache (z. B. `customizer_syntax_invalid`, `customizer_unsafe_code`) und Lösungshinweis. Für die Korrektur steht der [Theme-Explorer](EDITOR.md) bereit.

### Customizer des Themes `cms-default`

Konfiguration: `CMS/themes/cms-default/admin/customizer/config.php`, Hilfsfunktionen `customizer/helpers.php`, Oberfläche `customizer/partials/page.php`. Bereiche (Tabs, `?tab=`):

| Tab | Inhalt (Auszug) |
|---|---|
| `header` – Header & Logo | Logo-Typ (Bild/Text), Logo-Bild, -Höhe, -Text, Tagline, Titel neben Logo, Such-, Anmelde- und Registrieren-Button, Farbstreifen |
| `navigation` | Leiste unter dem Header, Hamburger-Menü, Schriftgröße, Großbuchstaben, Buchstabenabstand |
| `layout` – Layout & Design | Maximale Seitenbreite, Sticky Header, Inhaltslayout, Textspaltenbreite, Eckradius, Kartenabstand, „Nach oben“-Button |
| `colors` – Farben | Akzent/Hover, Textfarben (primär, weich, gedämpft), Hintergrund, Surface, Trennlinien, Header, Links, Kategorie-Leiste u. a. |
| `typography` | Schriften und Größen (lokale Schriften aus der [Schriftverwaltung](FONTS.md)) |
| `footer` | Spalten, Texte, Farben, Legal-Leiste |
| `blog` – Blog & Artikel | Darstellung von Archiven und Einzelbeiträgen |
| `homepage` – Startseite | Aufbau der Startseite |
| `advanced` – Erweitert | Zusätzliches CSS und Expertenoptionen |

### Speicherung

- Tabelle `cms_theme_customizations` (`theme_slug`, `setting_category`, `setting_key`, `setting_value`, optional `user_id`; eindeutig je Theme/Kategorie/Schlüssel/Benutzer).
- API: `ThemeCustomizer::instance()->get($category, $key, $default)`, `set()`, `setMultiple()`, `reset()`, `resetAll()`, `export()`, `import()`.
- `ThemeCustomizer::generateCSS()` erzeugt die CSS-Variablen, die `ThemeManager::renderCustomStyles()` im `<head>` ausgibt.
- Werte gelten **pro Theme**; nach einem Theme-Wechsel greifen die Einstellungen des neuen Themes.

### Eigener Customizer in einem Theme

Ein Theme stellt seinen Customizer bereit, indem es `admin/customizer.php` mitliefert. Die Datei erhält `$embedInAdminLayout` und sollte ein CSRF-Token (eigene Aktion, z. B. `theme_customizer`) erzeugen und prüfen. Details: [../../theme/THEME-DEVELOPMENT.md](../../theme/THEME-DEVELOPMENT.md).

### Verwandte Dokumente

[EDITOR.md](EDITOR.md) · [FONTS.md](FONTS.md) · [DESIGN-SETTINGS.md](DESIGN-SETTINGS.md) · [../../theme/DESIGN-SYSTEM.md](../../theme/DESIGN-SYSTEM.md)
