# 365CMS – Projektdokumentation | Abschnitt: Theme – Design-System

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Referenz-Theme:** `cms-default` 1.0.9 („Meridian“)

## English (summary)

There are two visual systems: the **frontend theme** (here `cms-default`, a single `style.css` built on CSS custom properties that the theme customizer overrides at runtime) and the **admin/member UI** (Tabler with Tabler Icons plus `CMS/assets/css/admin*.css`, `member-dashboard.css`). Theme design tokens are declared in `:root` and rewritten by `MeridianCMSDefaultTheme::outputCustomStyles()` from customizer values; fonts come from the font manager (local files) or Google Fonts depending on `privacy_use_local_fonts`.

## Deutsch

### Design-Tokens von `cms-default` (`style.css`, `:root`)

| Gruppe | Variablen (Standard) |
|---|---|
| Text | `--ink` `#1a1a18`, `--ink-soft` `#3d3d3a`, `--ink-muted` `#7a7a74`, `--ink-ghost` `#b8b8b0` |
| Flächen | `--ground` `#f7f6f2` (warmes Off-White), `--surface` `#ffffff`, `--surface-tint` `#f2f1ec` |
| Linien | `--rule` `#e2e0d8`, `--rule-heavy` `#c8c6bc` |
| Akzent | `--accent` `#c0862a` (Deep Amber), `--accent-light`, `--accent-mid`; zur Laufzeit zusätzlich `--accent-dark` |
| Code | `--code-bg` `#1e1e1c`, `--code-border` `#2e2e2a` |
| Tags | `--tag-bg` `#edece6`, `--tag-color` `#5a5a52` |
| Schrift | `--font-serif` Libre Baskerville, `--font-sans` DM Sans, `--font-mono` DM Mono |
| Layout | `--nav-h` 52px, `--max` 1140px (Seitenbreite), `--col` 680px (Textspalte), `--r` 3px (Radius), `--card-gap` |
| Schatten | `--shadow-sm`, `--shadow-md`, `--shadow-lg` |

Grundtypografie: 15 px, Zeilenhöhe 1,6, Sticky-Footer-Layout (`body` als Flex-Spalte).

### Customizer → CSS-Variablen

`outputCustomStyles()` liest Werte über `ThemeCustomizer::get()` und gibt einen `:root`-Block mit Nonce aus:

| Customizer (Bereich → Schlüssel) | Variable / Wirkung |
|---|---|
| `colors → accent_color`, `accent_dark_color` | `--accent`, `--accent-dark`, abgeleitet `--accent-light`, `--accent-mid` |
| `colors → ink_color`, `ink_soft_color`, `ink_muted_color` | `--ink`, `--ink-soft`, `--ink-muted` (+ abgeleitet `--ink-ghost`) |
| `colors → ground_color`, `surface_color`, `surface_tint_color`, `rule_color` | Flächen und Linien |
| `colors → header_bg_color`, `header_stripe_color`, `link_color`, `link_hover_color`, `category_bar_bg`, `category_bar_text` | Header, Links, Kategorie-Leiste |
| `footer → footer_bg_color`, `footer_text_color`, `footer_accent_color` | Footer |
| `layout → max_width`, `post_col_width`, `border_radius`, `card_gap`, `sticky_header` | `--max`, `--col`, `--r`, `--card-gap`, Sticky-Verhalten |
| `typography → font_size_base`, `line_height`, `heading_weight`, `font_family_body`, `font_family_heading`, `letter_spacing_headings`, `h1_size` … | Schriftgrößen, -familien, Gewichte |

### Schriften

- Standard: Libre Baskerville (Überschriften), DM Sans (Text), DM Mono (Code).
- Mit `privacy_use_local_fonts = 1` werden lokal installierte Schriften aus `uploads/fonts/` verwendet ([../admin/themes-design/FONTS.md](../admin/themes-design/FONTS.md)); sonst Google Fonts mit Preconnect.

### Admin- und Mitglieder-Oberfläche

- **Tabler** (`CMS/assets/tabler/`) mit **Tabler Icons** (`CMS/assets/tabler-icons/`, Klassen `ti ti-<name>`).
- Eigene Styles: `CMS/assets/css/admin*.css` (u. a. `admin-plugins.css` für ein einheitliches Plugin-Design), `member-dashboard.css`, `editorjs-content.css` (Inhaltsdarstellung), `hub-sites.css`, `cms-cookie-consent.css`, `cms-mfa.css`.
- Mitglieder-Dashboard-Farben (Primär, Akzent, Hintergrund, Karten, Text, Rahmen) werden im Admin unter *Mitglieder-Dashboard → Design* gesetzt.
- Login-Seiten der CMS-Loginseite haben ein eigenes, konfigurierbares Farbschema ([../admin/themes-design/CMS-LOGINPAGE.md](../admin/themes-design/CMS-LOGINPAGE.md)).

### Gestaltungsregeln

1. Farben, Abstände, Radien ausschließlich über Variablen – dann greifen Customizer-Änderungen überall.
2. Kontraste mindestens WCAG AA (Text auf `--ground`/`--surface`).
3. Keine Inline-`style`-Attribute in neuen Templates (CSP); falls unvermeidbar, schreibt der Core sie in Nonce-Klassen um.
4. Responsiv: Breakpoint des mobilen Menüs bei 720 px.

### Verwandte Dokumente

[COMPONENTS.md](COMPONENTS.md) · [THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md) · [../admin/themes-design/CUSTOMIZER.md](../admin/themes-design/CUSTOMIZER.md) · [../assets/css/README.md](../assets/css/README.md)
