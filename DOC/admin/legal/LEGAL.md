# 365CMS – Projektdokumentation | Abschnitt: Admin – Rechtsseiten

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/legal-sites` | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_legal_sites` | **Core-Modul:** `legal`

## English (summary)

`/admin/legal-sites` (`CMS/admin/legal-sites.php` → `CMS/admin/modules/legal/LegalSitesModule.php` → `CMS/admin/views/legal/sites.php`) manages four legal texts – imprint, privacy policy, terms and revocation – based on a **legal profile** (company data, contacts, services in use) and DACH templates (template version `2026.07.27`). Texts can be stored as settings and published as CMS pages. Actions: `save`, `save_profile`, `generate`, `create_page`, `create_all_pages`.

## Deutsch

### Die vier Rechtstexte

| Typ | Seitentitel | Slug | Option (Text) | Option (Seiten-ID) |
|---|---|---|---|---|
| `imprint` | Impressum | `impressum` | `legal_imprint` | `imprint_page_id` |
| `privacy` | Datenschutzerklärung | `datenschutz` | `legal_privacy` | `privacy_page_id` |
| `terms` | AGB | `agb` | `legal_terms` | `terms_page_id` |
| `revocation` | Widerrufsbelehrung | `widerruf` | `legal_revocation` | `revocation_page_id` |

Texte sind HTML (max. 60 000 Zeichen) und werden beim Speichern bereinigt.

### Rechtsprofil (`save_profile`)

Das Profil (`legal_profile_*`) füllt die Vorlagen automatisch:

| Gruppe | Felder |
|---|---|
| Anbieter | Rechtsform/Typ, Firmenname, Inhaber, Geschäftsführung, Straße, PLZ, Ort, Land, Telefon, E-Mail, Website |
| Register & Steuer | Registergericht, Registernummer, USt-IdNr. |
| Verantwortlich | Inhaltlich Verantwortlicher, Datenschutz-Kontakt (Name, E-Mail) |
| Hosting | Hosting-Anbieter und -Adresse |
| Eingesetzte Dienste | Analyse-Dienst (selbst gehostet?), Newsletter-Anbieter, Zahlungsanbieter, externe Medien, Webfonts (Anbieter/Quelle), essenzielles Cookie (Name, Zweck) |
| Optionen | Minimaler Datenschutzmodus |

**Vorlagenprofil** (`legal_template_profile`): `dach_de` (Deutschland), `dach_at` (Österreich), `dach_ch` (Schweiz), `dach_generic` (neutrales Skelett).

### Aktionen

| Aktion | Wirkung |
|---|---|
| `save` | Rechtstexte manuell speichern |
| `generate` | Text eines Typs aus Vorlage und Profil erzeugen (in das Formular) |
| `create_page` | CMS-Seite für einen Typ anlegen bzw. aktualisieren und die Seiten-ID speichern |
| `create_all_pages` | alle vier Seiten in einem Schritt |

### Verwendung im System

- Die Seiten-IDs werden im Footer des Themes und auf den CMS-Login-Seiten verlinkt. Der Checkout verwendet eigene Einstellungen (`terms_page_id`, `cancellation_page_id` in den Paketeinstellungen, siehe [../subscription/PACKAGES.md](../subscription/PACKAGES.md)).
- Nach Änderungen an eingesetzten Diensten (z. B. neues Tracking) Profil aktualisieren, Text neu erzeugen und Seite aktualisieren.

### Verwandte Dokumente

[README.md](README.md) · [COOKIES.md](COOKIES.md) · [DSGVO.md](DSGVO.md)
