# 365CMS – Projektdokumentation | Abschnitt: Admin – Landingpage

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/landing-page` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_landing_page`

## English (summary)

The landing page editor (`CMS/admin/landing-page.php` → `CMS/admin/modules/landing/LandingPageModule.php` → `CMS/admin/views/landing/page.php`) configures a start page made of hero, feature cards, content area and footer. Data is stored in `cms_landing_sections` and served by `CMS\Services\LandingPageService` (sub-services in `CMS/core/Services/Landing/`). The theme decides whether to show it: in **cms-default** the customizer option *Homepage → Mode = Landing* renders `partials/home-landing.php`; otherwise the blog home page is shown.

Tabs: `header`, `content`, `footer`, `design`, `plugins`. Actions: `save_header`, `save_content`, `save_feature`, `delete_feature`, `save_footer`, `save_design`, `save_plugin`.

## Deutsch

### Aktivierung im Theme

Die Landingpage wird nicht automatisch angezeigt. Im Theme **cms-default**:

1. `/admin/theme-editor?tab=homepage` öffnen,
2. Startseiten-Modus `landing` wählen (Standard `posts` = Blog-Startseite),
3. speichern – die Startseite rendert nun `CMS/themes/cms-default/partials/home-landing.php`, das `LandingPageService` ausliest.

Eigene Themes können den Service ebenso nutzen (`LandingPageService::getInstance()->getHeader()`, `getFeatures()`, `getFooter()`, `getDesign()`, `getContentSettings()`).

### Tabs und Felder

| Tab | Felder |
|---|---|
| **Header** (`save_header`) | Badge-Text, Titel, Untertitel, Beschreibung, Hero-Text, Hintergrundbild, CTA-Text und -URL, primärer Button |
| **Inhalt** (`save_content`) | Inhaltstyp `content_type`: `features` (Feature-Karten, Standard), `text` (Freitext `content_text`) oder `posts` (neueste Beiträge, Anzahl `posts_count`) |
| **Features** (`save_feature`, `delete_feature`) | Karten mit Icon, Titel, Beschreibung und Sortierung (`sort_order` 0–999) |
| **Footer** (`save_footer`) | Footer anzeigen, Inhalt, Copyright, Button-Text und -URL, Hintergrund- und Textfarbe |
| **Design** (`save_design`) | Hero-Verlauf (Start/Ende), Hero-Rahmen und -Abstand, Hintergründe für Features und Inhaltsbereich, Karten (Hintergrund, Hover, Rahmenfarbe/-breite/-radius, Schatten, Icon-Layout), Spaltenanzahl, Innenabstand, Button-Radius |
| **Plugins** (`save_plugin`) | Plugin-Bereiche auf der Landingpage (siehe unten) |

Alle Eingaben laufen durch `LandingSanitizer` (Klartext-/Längenbegrenzung, Farben, URLs, Allowlists).

### Plugin-Integration

Plugins melden Landingpage-Bausteine über den Filter `landing_page_plugins` an:

```php
\CMS\Hooks::addFilter('landing_page_plugins', static function (array $plugins): array {
    $plugins['my-events'] = [
        'name'        => 'Event-Teaser',
        'description' => 'Nächste Veranstaltungen',
        'version'     => '1.0.0',
        'targets'     => ['content'],          // erlaubt: header, content, footer
    ];
    return $plugins;
});
```

Im Tab *Plugins* legt der Administrator fest, in welchem Bereich (`areas[]`) ein Plugin den Standardinhalt ersetzt (Override). Das Theme ruft `LandingPageService::renderPluginOverride($area, $context)` auf.

### Datenmodell

`cms_landing_sections` (`id`, `type`, `data` JSON, `sort_order`). Der `type` unterscheidet Header, Feature, Footer, Design, Content-Einstellungen und Plugin-Overrides. Fehlende Standarddatensätze legt `ensureDefaults()` beim ersten Aufruf an.

### Verwandte Dokumente

[README.md](README.md) · [../themes-design/CUSTOMIZER.md](../themes-design/CUSTOMIZER.md) · [../../plugins/PLUGIN-DEVELOPMENT.md](../../plugins/PLUGIN-DEVELOPMENT.md)
