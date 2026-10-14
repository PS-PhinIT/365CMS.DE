# 365CMS – Projektdokumentation | Abschnitt: Admin – Cookie-Manager & Einwilligung

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Status:** Stable
> **Route:** `/admin/cookie-manager` (Alt-Route `/admin/cookies`) | **Capability:** `manage_settings` | **CSRF-Aktion:** `admin_cookies` | **Core-Modul:** `legal`

## English (summary)

The cookie manager (`CMS/admin/cookie-manager.php` → `CMS/admin/modules/legal/CookieManagerModule.php` → `CMS/admin/views/legal/cookies.php`) maintains consent categories (`cms_cookie_categories`), services (`cms_cookie_services`) and banner settings. The frontend banner is rendered by `CMS\Services\CookieConsentService::render()` with the CMS's own script `CMS/assets/js/cookieconsent-init.js`; the decision is stored in the first-party cookie `cc_cookie`. Visitors can change their choice at `/cookie-einstellungen`. Actions: `save_settings`, `save_category`, `delete_category`, `save_service`, `delete_service`, `import_curated_service`, `run_scan`.

## Deutsch

### Kategorien

| Slug | Name | Pflicht | Reihenfolge |
|---|---|---|---|
| `necessary` | Essenziell | ja (nicht abwählbar) | 0 |
| `functional` | Funktional | nein | 10 |
| `analytics` | Analytics | nein | 20 |
| `marketing` | Marketing | nein | 30 |
| `external_media` | Externe Medien | nein | 40 |

Eigene Kategorien: Name, Slug, Beschreibung, Pflicht, aktiv, Sortierung, optionale Skripte (`save_category` / `delete_category`).

### Dienste

Ein Dienst gehört zu einer Kategorie und beschreibt Anbieter, Zweck, gesetzte Cookies (`cookie_names`) und optional einen Code-Schnipsel (`code_snippet`), der erst nach Zustimmung ausgeführt wird.

**Kuratierte Dienste** (`import_curated_service`): Google Analytics, Google Tag Manager, Matomo (auch als selbst gehostete Variante), Facebook Pixel, LinkedIn Insight Tag, YouTube, Vimeo, Google Maps, HubSpot sowie „365CMS Kernfunktionen“ (essenziell).

### Scanner (`run_scan`)

Durchsucht `CMS/themes/`, `CMS/includes/` und `CMS/assets/js/` sowie die Analytics-Einstellungen nach bekannten Mustern (z. B. `google-analytics.com`, `_paq`, `youtube.com/embed`) und schlägt passende kuratierte Dienste mit Fundstelle vor.

### Banner-Einstellungen (`save_settings`)

| Option | Bedeutung |
|---|---|
| `cookie_consent_enabled` (Fallback `cookie_banner_enabled`) | Consent-Banner aktiv |
| `cookie_banner_position`, `cookie_banner_style` | Position und Darstellung |
| `cookie_banner_text`, `cookie_essential_text` | Texte |
| `cookie_accept_text`, `cookie_reject_text` | Button-Beschriftungen |
| `cookie_policy_url` | Link zur Datenschutzerklärung |
| `cookie_lifetime_days` | Gültigkeit der Entscheidung |
| `cookie_matomo_*` | Matomo-Details: selbst gehostete URL, Site-ID, Hosting-Region, IP-Anonymisierung, ohne Cookies, DNT, Log-Aufbewahrung, DSGVO-Hinweis |

### Ablauf im Frontend

1. `CookieConsentService::render()` gibt Stylesheet und `cookieconsent-init.js` aus (nur bei aktivem Modul `legal` und aktiviertem Consent; nie im Adminbereich).
2. Der Besucher wählt Kategorien; die Entscheidung wird als Cookie `cc_cookie` (Pfad `/`, `SameSite=Lax`, `Secure` unter HTTPS) gespeichert.
3. Server- und Clientseite fragen die Zustimmung ab: `CookieConsentService::hasConsentForCategory('analytics')` bzw. `hasConsentForService('youtube')`. `SeoAnalyticsRenderer` lädt GA4/Matomo/GTM/Pixel nur mit Zustimmung.
4. `/cookie-einstellungen` zeigt die Einstellungsseite zum Ändern oder Widerrufen.

### Plugin- und Theme-Integration

```php
$consent = \CMS\Services\CookieConsentService::getInstance();
if ($consent->hasConsentForService('google_maps', 'external_media')) {
    // Karte direkt einbetten
} else {
    // Platzhalter mit Hinweis „Externe Medien zulassen“
}
```

### Verwandte Dokumente

[README.md](README.md) · [LEGAL.md](LEGAL.md) · [../seo/ANALYTICS.md](../seo/ANALYTICS.md)
