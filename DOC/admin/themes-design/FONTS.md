# 365CMS – Projektdokumentation | Abschnitt: Admin – Schriftverwaltung

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/font-manager` (Alt-Route `/admin/fonts-local` leitet um) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_font_manager`

## English (summary)

The font manager (`CMS/admin/font-manager.php` → `CMS/admin/modules/themes/FontManagerModule.php` → `CMS/admin/views/themes/fonts.php`) selects heading/body fonts, base size and line height, scans the active theme for externally loaded fonts and downloads Google Fonts **locally** (GDPR) into `CMS/uploads/fonts/`. Registered font files are tracked in `cms_custom_fonts`.

Actions: `save`, `scan_theme_fonts`, `download_detected_fonts`, `download_google_font`, `delete_font`.

## Deutsch

### Einstellungen (`action=save`)

| Feld | Option | Wertebereich |
|---|---|---|
| Überschriften-Schrift | `font_heading` | Systemschrift, kuratierte Bibliothek oder lokal installierte Schrift |
| Fließtext-Schrift | `font_body` | wie oben |
| Basisgröße | `font_size_base` | 12–24 px (Standard 16) |
| Zeilenhöhe | `font_line_height` | 1,0–2,5 (Standard 1,6) |
| Lokale Schriften erzwingen | `privacy_use_local_fonts` | an/aus – das Theme lädt dann keine Schriften von `fonts.googleapis.com` |
| Google Fonts als Fallback (Theme-Customizer `typography.google_fonts`) | seit 3.4.13 standardmäßig **aus** | nur wenn aktiviert und keine lokalen Schriften aktiv sind, lädt `cms-default` Fonts von Google; sonst greifen die System-Fallbacks (`Georgia`, `system-ui`) |

**Kuratierte Auswahl (Auszug):** Inter, Space Grotesk, Sora, Barlow, Roboto, Open Sans, Lato, Montserrat, Poppins, Source Sans, Nunito, Oswald, Rajdhani, DM Sans, Libre Baskerville, Playfair Display, Merriweather, JetBrains Mono, Fira Code sowie Systemschriften (system-ui, Arial, Georgia, Verdana …) mit passenden Fallback-Stacks.

### Theme-Scan (`scan_theme_fonts`)

Durchsucht das aktive Theme nach Schriftverweisen (`@import`/`<link>` auf Google Fonts, `font-family`-Angaben):

- Dateitypen: `css`, `php`, `js`, `json`, `txt`, `md`; ausgelassen werden `vendor`, `node_modules`, `cache`, `.git`.
- Grenzen: 300 Dateien, 256 KB je Datei, 10 MB gesamt.
- Ergebnis wird 15 Minuten zwischengespeichert (`font_scan_cache_*`).
- `download_detected_fonts` lädt alle gefundenen Google-Schriften auf einmal lokal herunter.

### Google Font lokal installieren (`download_google_font`)

1. Familienname eingeben (z. B. „Inter“).
2. Das CMS lädt die CSS-Definition (`fonts.googleapis.com/css2`, Gewichte 300–700, Subsets `latin` und `latin-ext`) und die Schriftdateien von `fonts.gstatic.com`.
3. Nur diese beiden Hosts sind erlaubt. Grenzen: max. 20 Dateien, 5 MB je Datei, 15 MB gesamt; erlaubte Formate `woff2`, `woff`, `ttf`, `otf`.
4. Dateien landen in `CMS/uploads/fonts/`, eine lokale CSS-Datei wird erzeugt und der Eintrag in `cms_custom_fonts` (`source = google-fonts-local`) gespeichert.

### Schrift entfernen (`delete_font`)

Löscht Datenbankeintrag sowie Schrift- und CSS-Dateien. Ist die Schrift noch als Überschrift/Fließtext gewählt, vorher eine andere Schrift setzen.

### Datenschutz

Mit lokal installierten Schriften und aktivem `privacy_use_local_fonts` werden beim Seitenaufruf keine IP-Adressen an Google übertragen. Das ist die empfohlene Einstellung für den DACH-Raum.

### Verwandte Dokumente

[CUSTOMIZER.md](CUSTOMIZER.md) · [../legal/COOKIES.md](../legal/COOKIES.md) · [../../theme/DESIGN-SYSTEM.md](../../theme/DESIGN-SYSTEM.md)
