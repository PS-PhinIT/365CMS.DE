# 365CMS – Projektdokumentation | Abschnitt: Admin – Schriftverwaltung

> **Stand:** 2026-10-23 | **Version:** 3.5.4 | **Status:** Stable
> **Route:** `/admin/font-manager` (Alt-Route `/admin/fonts-local` leitet um) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_font_manager`

## English (summary)

The font manager (`CMS/admin/font-manager.php` → `CMS/admin/modules/themes/FontManagerModule.php` → `CMS/admin/views/themes/fonts.php`) selects heading/body fonts, base size and line height, scans the active theme for externally loaded fonts and downloads Google Fonts **locally** (GDPR) into `CMS/uploads/fonts/`. Registered font files are tracked in `cms_custom_fonts`.

Actions: `save`, `scan_theme_fonts`, `download_detected_fonts`, `download_google_font`, `refresh_font`, `delete_font`.

Since 3.5.4 downloads request Google Fonts with exactly one modern browser User-Agent, so WOFF2 (≈ 25 KiB per style) is stored instead of TTF (≈ 140 KiB). Older TTF/OTF installs are flagged as “Legacy-Format” and can be converted with **Als WOFF2 neu laden** (`refresh_font`). The frontend preloads the primary WOFF2 file (latin, normal, 400) and versions the font CSS by file time.

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
2. Das CMS lädt die CSS-Definition (`fonts.googleapis.com/css2`, Gewichte 300–700; fehlen Gewichte, folgt der Legacy-Endpunkt `css`, danach `css2` ohne Gewichtsachse) und nur die Schriftdateien der Subsets `latin` und `latin-ext` von `fonts.gstatic.com`.
3. **Format:** Google wählt das Format anhand des User-Agents. Der Font Manager sendet genau **einen** vollständigen Chrome-User-Agent und erhält dadurch WOFF2 (ca. 25 KiB pro Schnitt statt ca. 140 KiB TTF). Bis 3.5.3 sendete `CMS\Http\Client` zwei `User-Agent`-Header, worauf Google TTF lieferte. Liefert Google dennoch kein WOFF2, erscheint ein Hinweis und ein Eintrag im Kanal `admin.font-manager`.
4. Nur diese beiden Hosts sind erlaubt. Grenzen: max. 20 Dateien, 5 MB je Datei, 15 MB gesamt; erlaubte Formate `woff2`, `woff`, `ttf`, `otf` (Magic-Bytes werden geprüft).
5. Das lokale CSS wird **neu aufgebaut** statt übernommen: nur `font-family`, `font-style`, `font-weight`, `font-stretch`, `font-display` (immer `swap`), `src` und `unicode-range`; `src` darf nur lokal gespeicherte Dateien referenzieren. Blöcke mit externen oder unerwarteten Werten werden verworfen, nicht referenzierte Dateien gelöscht.
6. Dateien landen in `CMS/uploads/fonts/`, der Eintrag in `cms_custom_fonts` (`source = google-fonts-local`) speichert als `file_path` die Primärdatei (Subset `latin`, normal, Gewicht 400), die im Frontend vorgeladen wird.

### Schrift neu laden (`refresh_font`)

Für lokal gespeicherte Google Fonts. Schriften, die noch als TTF/OTF vorliegen, sind in der Liste als **Legacy-Format** markiert; der Button **Als WOFF2 neu laden** lädt sie erneut, ersetzt CSS und Datenbankeintrag (Slug, Zuordnungen und Font-Stack bleiben) und löscht danach nicht mehr referenzierte Altdateien. Schlägt der Download fehl, bleibt die bisherige Schrift unverändert.

### Auslieferung im Frontend

Bei aktivem `privacy_use_local_fonts` bindet `Bootstrap` nur die als Überschrift/Fließtext gewählten Schriften ein (Filter `local_font_slugs`):

- `<link rel="preload" as="font" type="font/woff2" crossorigin>` für die Primärdatei – der Browser lädt die Schrift parallel zum CSS.
- Stylesheet mit `?ver=<Dateizeit>`: `.htaccess` cached CSS ein Jahr `immutable`, nach „Neu laden“ holen Browser so sofort die neue Fassung.
- Pfade aus der Datenbank werden nur akzeptiert, wenn sie direkt unter `uploads/fonts/` liegen.
- WOFF/WOFF2 sind bereits komprimiert und werden von Brotli/Deflate ausgenommen; TTF/OTF werden weiterhin komprimiert.

### Schrift entfernen (`delete_font`)

Löscht Datenbankeintrag sowie Schrift- und CSS-Dateien. Ist die Schrift noch als Überschrift/Fließtext gewählt, vorher eine andere Schrift setzen.

### Datenschutz

Mit lokal installierten Schriften und aktivem `privacy_use_local_fonts` werden beim Seitenaufruf keine IP-Adressen an Google übertragen. Das ist die empfohlene Einstellung für den DACH-Raum.

### Verwandte Dokumente

[CUSTOMIZER.md](CUSTOMIZER.md) · [../legal/COOKIES.md](../legal/COOKIES.md) · [../../theme/DESIGN-SYSTEM.md](../../theme/DESIGN-SYSTEM.md)
