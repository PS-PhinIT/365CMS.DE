# 365CMS – Admin: Erklärung zur Barrierefreiheit

> **Stand:** 2026-11-12 | **Version:** 4.0.0 | **Status:** Stable
> **Route:** `/admin/legal-sites` (Karte *Erklärung zur Barrierefreiheit*) | **Capability:** `manage_settings`

## English (summary)

The legal pages module creates an accessibility statement (German and English) from a few fields: compliance status,
standard, legal basis, known issues, creation/review date and method, feedback contact and enforcement body. Saving can
update the assigned page (`/barrierefreiheit`, `/en/accessibility`) and add a footer link. A yearly reminder asks for a
review. The generated text is a template, not legal advice.

## Deutsch

### Felder

| Feld | Hinweis |
|---|---|
| Stand der Vereinbarkeit | vollständig, teilweise (Standard) oder nicht vereinbar |
| Standard | Standard `WCAG 2.1 AA (EN 301 549)` |
| Rechtsgrundlage | z. B. BFSG oder Landesgesetz |
| Nicht barrierefreie Inhalte | je Zeile ein Punkt, DE und EN (höchstens 50) |
| Erstellt am / zuletzt geprüft / Prüfmethode | Erstelldatum ist Pflicht; Methode Selbstbewertung oder externe Prüfung |
| Feedback | Name, E-Mail, Telefon, Formular-Adresse |
| Durchsetzungsverfahren | Stelle, Anschrift, E-Mail, Adresse |
| Footer-Link | Link „Barrierefreiheit“ im Footer-Menü (`footer_legal`, sonst `footer`) |
| Seite aktualisieren | schreibt den Text in die zugeordnete Seite (`accessibility_page_id`) |

### Ablauf

1. Felder ausfüllen und speichern. Die Seite wird wie Impressum und Datenschutz angelegt bzw. zugeordnet
   (DE `/barrierefreiheit`, EN `/en/accessibility`).
2. Einmal im Jahr erinnert ein Hinweis im Admin an die Prüfung; *Heute überprüft* setzt das Prüfdatum.

Geprüft (4.0.0, Laufzeit): Status, Mängel und Feedback-Adresse gespeichert → öffentliche Seite enthält beide Angaben,
EN-Seite antwortet mit 200, Footer verlinkt `/barrierefreiheit`.

### Dateien

`CMS/core/Services/Legal/AccessibilityStatementService.php`, `CMS/admin/views/legal/accessibility-card.php`,
`CMS/admin/legal-sites.php`, `CMS/admin/modules/legal/LegalSitesModule.php`
