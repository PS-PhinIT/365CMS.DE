# Interne Bild-Assets

> **Stand:** 2026-09-06 | **CMS-Version:** 3.5.0 | **Status:** Aktuell

## Kurzbeschreibung

Der Ordner `CMS/assets/images/` enthält die produktiven 365CMS-Bildressourcen für
Dashboard-Navigation, Logo, Branding und kompakte UI-Flächen. Die Dateien sind
interne Runtime-Assets und kein Drittanbieter-Paket.

## Quellordner

- `CMS/assets/images/`

## Verwendung in 365CMS

- Dashboard-Icons für Admin- und Member-Bereich
- Logos mit Markenzeichen, Text und transparentem Markenzeichen
- kompakte Varianten für Navigation, Header, Badges und Branding

## Aktueller Bildbestand

### Dashboard-Icons

| Datei | Abmessungen | Verwendung |
|---|---:|---|
| `365CMS-DASHBOARD-Admin-100px.png` | 90 × 100 px | Admin-Dashboard / Administration |
| `365CMS-DASHBOARD-Member-100px.png` | 90 × 100 px | Member-Dashboard / Mitgliederbereich |

### Logos mit Text

| Datei | Abmessungen | Verwendung |
|---|---:|---|
| `LOGO_365CMS-75px.png` | 101 × 75 px | kompakte Logo-Fläche |
| `LOGO_365CMS-100px.png` | 135 × 100 px | Standard-Logo |
| `LOGO_365CMS-120px.png` | 280 × 120 px | breites Header-Logo |
| `LOGO_365CMS-125px.png` | 169 × 125 px | mittlere Logo-Fläche |
| `LOGO_365CMS-150px.png` | 203 × 150 px | große Logo-Fläche |

### Reiner Schriftzug

| Datei | Abmessungen | Verwendung |
|---|---:|---|
| `LOGO_365CMS-onlyText-125px.png` | 125 × 20 px | kompakter Schriftzug |
| `LOGO_365CMS-onlyText-160px.png` | 160 × 25 px | mittlerer Schriftzug |
| `LOGO_365CMS-onlyText-200px.png` | 200 × 31 px | großer Schriftzug |

### Logo ohne Schriftzug

| Datei | Abmessungen | Verwendung |
|---|---:|---|
| `LOGO_365CMS-wo_Text-50px.png` | 48 × 50 px | kleines Markenzeichen |
| `LOGO_365CMS-wo_Text-75px.png` | 73 × 75 px | mittleres Markenzeichen |
| `LOGO_365CMS-wo_Text-150px.png` | 145 × 150 px | großes Markenzeichen |

## Pflege- und Ladehinweise

- Die kanonischen Dateien liegen ausschließlich unter `CMS/assets/images/`.
- Neue Varianten müssen hier ergänzt und in `DOC/FILELIST.md` nachgeführt werden.
- Pfade im PHP-Code sollen über `cms_asset_url('images/<datei>')` erzeugt werden.
- Dateinamen und Varianten nicht still umbenennen, da sie von Admin- und
  Theme-Templates referenziert werden.

## Website / GitHub

- Website: –
- GitHub: –