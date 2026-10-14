# 365CMS – Projektdokumentation | Abschnitt: Admin – Recht & Datenschutz

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable

## English (summary)

This folder documents the sidebar group **Legal** (*Recht*, core module `legal`): legal pages (imprint, privacy policy, terms, revocation), the cookie/consent manager and GDPR data requests (access/export Art. 15 and erasure Art. 17). All pages require `manage_settings`.

## Deutsch

| Menüpunkt | Route | Dokument |
|---|---|---|
| Rechtsseiten | `/admin/legal-sites` | [LEGAL.md](LEGAL.md) |
| Cookie-Manager | `/admin/cookie-manager` (Alt-Route `/admin/cookies`) | [COOKIES.md](COOKIES.md) |
| Auskunft & Löschen | `/admin/data-requests` | [DSGVO.md](DSGVO.md), [DELETION-REQUESTS.md](DELETION-REQUESTS.md) |

Die Alt-Routen `/admin/privacy-requests` und `/admin/deletion-requests` (sowie `/admin/data-access`, `/admin/data-deletion`) leiten auf `/admin/data-requests` um.

### Zusammenspiel

```text
Rechtsprofil (Firma, Kontakt, Dienste) ──► Vorlagen ──► Seiten „Impressum“, „Datenschutz“, „AGB“, „Widerruf“
                                                             │
Cookie-Manager (Kategorien, Dienste, Banner) ──► Consent im Frontend ──► Analytics/Marketing/Medien laden
                                                             │
Mitglied: /member/privacy ──► Export (sofort) / Löschantrag (30 Tage Frist) ──► /admin/data-requests
```

> **Hinweis:** Die mitgelieferten Vorlagen sind ein Startpunkt und ersetzen keine Rechtsberatung. Texte vor der Veröffentlichung fachlich prüfen lassen.

### Verwandte Bereiche

- Lokale Schriften statt Google Fonts: [../themes-design/FONTS.md](../themes-design/FONTS.md)
- Tracking-Dienste: [../seo/ANALYTICS.md](../seo/ANALYTICS.md)
- Rechtliche Links auf den Login-Seiten: [../themes-design/CMS-LOGINPAGE.md](../themes-design/CMS-LOGINPAGE.md)
- Datenschutz im Member-Bereich: [../../member/MEMBER-SECURITY.md](../../member/MEMBER-SECURITY.md)
