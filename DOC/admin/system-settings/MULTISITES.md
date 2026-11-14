# 365CMS – Projektdokumentation | Abschnitt: Admin – MultiSites (Admin-Kontext je Domain)

> **Stand:** 2026-11-10 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/multisites` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_multisites`

## English (summary)

`/admin/multisites` (`CMS/admin/multisites.php` → `CMS/admin/views/settings/multisites.php`, CSS `admin-multisites.css`) manages the sites administered by one 365CMS installation. Each site has a name, a URL, an accent colour and optional extra host names. `AdminSiteContextService` stores them as JSON in the option `admin_sites`.

MultiSite is **only an admin context**. It controls:

- the accent colour,
- the site switcher in the sidebar,
- which plugin menus appear (plugin assignment),
- which admin-menu layout applies.

Content (pages, posts, hub sites, media, forms, …) is **not** assigned to sites. There are no built-in sites: without stored sites, the main URL (`SITE_URL`) is the only site.

## Deutsch

### Zweck und Abgrenzung

Eine 365CMS-Installation kann mehrere Domains administrieren, z. B. eine Hauptsite und eine Community-Site. MultiSites legt fest, **in welchem Kontext die Administration gerade arbeitet**:

- Akzentfarbe
- Site-Umschalter
- Plugin-Menüs
- Admin-Menü-Layout

Eine Site-Zuordnung für Inhalte gibt es bewusst nicht ([KLARSTELLUNGEN.md §3](../../workflow/specs/KLARSTELLUNGEN.md)):

- Seiten, Beiträge, Hub-Sites, Medien, Menüs und Formulare gehören zur Installation, nicht zu einer Site. Inhalte für eine weitere Domain laufen über die bestehende Hub-Site.
- Inhaltstabellen haben keine `site_id`-Spalte. Kalender, Revisionen, Übersetzungen, Suchindex, Export/Import und Statistiken kennen keine Site-Dimension.
- Absolute URLs (Feeds, Sitemap, Canonicals, Mails) nutzen die bestehende Host- bzw. Hub-Site-Auflösung, nicht die MultiSites-Liste.
- Es gibt keine fest eingebauten Sites. Ohne gespeicherte Liste gilt eine implizite Site aus `SITE_URL` und dem Site-Namen, mit der Standard-Akzentfarbe `#4f46e5`.

### Aufruf

| Weg | Ort |
|---|---|
| Sidebar, Profil *Klassisch* | **Einstellungen** (Kachelseite) › Kategorie *Allgemein* › **MultiSites** |
| Sidebar, Profil *Strukturiert* | **Konfiguration** (Kachelseite) › Kategorie *Allgemein* › **MultiSites** |
| Tab-Leiste der Einstellungen | Allgemein · Plugins · **MultiSites** · Admin-Menü · Beiträge & Sites. Weitere Tabs wie *Feeds* oder *Sicherheit* erscheinen, sobald ihre Datei unter `CMS/admin/settings-tabs/` existiert. |

Katalogeintrag `system.multisites`, siehe [../ADMIN-MENU.md](../ADMIN-MENU.md#3-katalog-der-core-seiten).

### Felder

| Feld | Regeln |
|---|---|
| **Name** | Pflicht, höchstens 60 Zeichen, HTML wird entfernt. Erscheint im Site-Umschalter und als Spalte in der Plugin-Zuweisung. |
| **URL** | Pflicht, `http://` oder `https://` mit Domain, **ohne Pfad**. Ohne Protokoll wird `https://` ergänzt. Die Domain samt `www`-Variante gehört automatisch zu den Hostnamen. |
| **Akzentfarbe** | Pflicht, `#rrggbb`. Weiße Schrift auf der Farbe und Links auf dem Seitenhintergrund `#f1f0ee` brauchen jeweils mindestens **4,5:1** Kontrast (WCAG AA). Ist die Farbe zu hell, wird nicht gespeichert; die Meldung nennt das erreichte Verhältnis und einen dunkleren Vorschlag. Die hellen und dunklen Varianten (`accent_soft`, `accent_strong`) werden abgeleitet. |
| **Weitere Hostnamen** | Optional, z. B. Staging- oder Alias-Domains. Trennung per Zeilenumbruch, Komma, Semikolon oder Leerzeichen. Internationalisierte Domains werden in die ASCII-Form (Punycode) umgewandelt. Ungültige Hostnamen werden mit Namen gemeldet. Ein Hostname darf nur zu **einer** Site gehören. |

Der **Site-Schlüssel** wird beim Anlegen aus der Domain gebildet, z. B. `example.com`; ein führendes `www.` entfällt. Bei Kollisionen wird `-2`, `-3` usw. angehängt. Der Schlüssel bleibt danach unverändert, auch wenn sich die URL ändert, weil Plugin-Zuweisung, Benutzer-Präferenzen und Admin-Menü-Layouts auf ihn verweisen.

### Aktionen

Alle Aktionen sind POST-Formulare mit dem CSRF-Token `admin_multisites`. Nach jeder erfolgreichen Aktion leitet die Seite zurück auf `/admin/multisites` und zeigt dort die Meldung (PRG-Muster). Bei Validierungsfehlern erscheint das Formular erneut, mit den eingegebenen Werten und markierten Feldern. Jede erfolgreiche Aktion schreibt einen Eintrag ins Audit-Log (Kategorie `setting`).

| Aktion | Wirkung | Audit-Aktion |
|---|---|---|
| Speichern (neu) | Legt eine Site an. Solange nur die implizite Site gilt, wird dabei die ganze wirksame Liste gespeichert. | `multisite.create` |
| Speichern (bestehend) | Aktualisiert Name, URL, Akzentfarbe und Hostnamen; der Schlüssel bleibt. Das Log enthält den Stand vorher und nachher. | `multisite.update` |
| Löschen | Entfernt die Site (mit Rückfrage). Die **letzte** Site lässt sich nicht löschen. Danach entfernt `PluginSiteAssignmentService::pruneUnknownSites()` Verweise auf die Site aus der Plugin-Zuweisung. `AdminMenuService::pruneUnknownSites()` setzt ihr Admin-Menü-Layout zurück (Audit `admin_menu.reset`). | `multisite.delete` (Stufe *warning*) |
| Nach oben / Nach unten | Ändert die Reihenfolge. Die **erste** Site ist die Standard-Site. | `multisite.reorder` |

### Welche Site ist aktiv?

`AdminSiteContextService::getCurrentSiteKey()` prüft in dieser Reihenfolge:

1. die Auswahl im **Site-Umschalter** der Sidebar. Sie ist je Benutzer in `admin_ui_prefs.site` gespeichert (`POST /admin/admin-ui` mit `action=switch_site`, siehe [../ADMIN-API-AJAX.md](../ADMIN-API-AJAX.md)). Eine gespeicherte Auswahl, deren Site inzwischen gelöscht wurde, wird ignoriert.
2. den **Hostnamen der Anfrage**: URL-Domain, `www`-Variante oder weiterer Hostname einer Site.
3. die **erste Site** der Liste (Standard-Site).

Mit nur einer Site zeigt die Sidebar statt des Umschalters ein festes Site-Kennzeichen.

### Was hängt am Site-Kontext?

| Bereich | Wirkung | Doku |
|---|---|---|
| Akzentfarbe | `partials/header.php` setzt `<html data-site="…">` und die CSS-Variablen `--cms-accent`, `--cms-accent-rgb`, `--cms-accent-soft` und `--cms-accent-strong`. Schaltflächen, Links und die aktive Navigation erscheinen in der Site-Farbe. | – |
| Site-Umschalter | Ab zwei Sites oben in der Sidebar, mit Farbmarke je Site | – |
| Plugin-Zuweisung | `/admin/plugin-assignment`, Option `admin_plugin_site_assignments`. Ein Plugin ohne Eintrag gilt für alle Sites. | [../plugins/PLUGINS.md](../plugins/PLUGINS.md#plugin-zuweisung) |
| Admin-Menü | Eigenes Layout je Site unter *Einstellungen › Admin-Menü*. Ein Site-Layout hat Vorrang vor dem Profil. | [../ADMIN-MENU.md](../ADMIN-MENU.md#2-welches-layout-gilt) |

### Speicherformat

Option `admin_sites` in `cms_settings`: eine JSON-Liste in der Reihenfolge der Sites.

```json
[
  {
    "key": "example.com",
    "label": "Beispiel",
    "url": "https://example.com",
    "accent": "#4f46e5",
    "accent_soft": "#eef2ff",
    "accent_strong": "#3730a3",
    "hosts": ["example.com", "www.example.com", "staging.example.com"]
  }
]
```

Beim Lesen wird jeder Eintrag normalisiert. Ungültige oder doppelte Einträge werden übersprungen. Fehlt die Option oder ist sie leer, gilt die implizite Site aus `SITE_URL`.

### Technik

| Baustein | Datei |
|---|---|
| Entry Point (Gate `isAdmin()` + `manage_settings`, Aktionen, Audit) | `CMS/admin/multisites.php` |
| View | `CMS/admin/views/settings/multisites.php` (Tab-Leiste aus `partials-tabs.php`) |
| Styles | `CMS/assets/css/admin-multisites.css` |
| Logik | `CMS\Services\AdminSiteContextService`: `getSites()`, `getConfiguredSites()`, `getCurrentSiteKey()`, `switchSite()`, `saveSite()`, `deleteSite()`, `moveSite()`, `accentContrast()`, `suggestAccent()` |
| Benutzer-Präferenz | `CMS\Services\AdminUiPreferenceService` (`admin_ui_prefs.site`) |
| Umschalter (JSON) | `CMS/admin/admin-ui.php`, `action=switch_site` |

MultiSites steht in beiden Profilen als Kachel unter *Einstellungen* bzw. *Konfiguration*.
