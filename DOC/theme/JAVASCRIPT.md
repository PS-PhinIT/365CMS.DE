# 365CMS – Projektdokumentation | Abschnitt: Theme – JavaScript

> **Stand:** 2026-10-02 | **Version:** 3.5.0 | **Referenz-Theme:** `cms-default` 1.0.9

## English (summary)

Theme JavaScript must be external, deferred and compatible with the strict CSP (nonce-based `script-src`, Trusted Types, no inline handlers, no `eval`). `cms-default` ships one file, `js/theme.js` (vanilla JS, no dependencies), loaded in `before_footer` with a version query (`?v=1.0.9`) or through `AssetOptimizerService` when minification is enabled. Core scripts for consent, analytics, web vitals, PhotoSwipe and site tables are injected by the core via `head`/`body_end`.

## Deutsch

### Einbindung

```php
// functions.php (vereinfacht)
\CMS\Hooks::addAction('before_footer', static function (): void {
    $src = MERIDIAN_THEME_URL . '/js/theme.js?v=' . MERIDIAN_THEME_VERSION;
    echo '<script src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" defer></script>';
}, 10);
```

- Externe Dateien brauchen kein Nonce (Quelle `'self'`), Inline-Skripte schon: `<script <?= \CMS\Security::instance()->nonceAttr() ?>>`.
- Mit `perf_minify_js = 1` liefert `AssetOptimizerService::getAssetUrl()` eine minifizierte Variante.
- Daten an Skripte über `data-*`-Attribute oder JSON in `<script type="application/json" …nonce>` übergeben – nicht über Inline-Code.

### Funktionen von `cms-default/js/theme.js`

| Bereich | Verhalten | Auslöser im Markup |
|---|---|---|
| Suche | Typ- und Sortierauswahl schicken das Formular sofort ab | `data-search-autosubmit` |
| Mobile Navigation | Öffnen/Schließen, ESC, Fokus | `#navToggle`, mobiles Menü |
| Dropdown-Navigation | Hover/Klick, Tastatur, Schließen bei Klick außerhalb | Menüeinträge mit Untermenü |
| Sticky Header | Schatten beim Scrollen | Header-Element |
| Header-Suche | Suchleiste ein-/ausblenden | Such-Button |
| Passwort-Anzeige | Sichtbarkeit umschalten (Theme-Loginseiten) | Passwort-Toggle |
| Teilen | Link in die Zwischenablage kopieren | Copy-Link-Buttons |
| Nach oben | Button nach Scrollen einblenden | Scroll-To-Top |
| Cookie-Banner (Theme) | nur wenn kein zentraler Consent aktiv ist; Cookie `meridian_cookie_consent` | `#cookieBanner`, `#cookieAccept`, `#cookieDecline` |
| Lazy Loading | `IntersectionObserver` für `img[loading="lazy"]`; vorhandenes `data-src` wird übernommen, Klasse `loaded` gesetzt | `img[loading="lazy"]` |
| Anker-Scroll | weiches Scrollen zu `#ziel` | interne Anker |
| Hinweise | automatisches Ausblenden | `data-autohide` |
| Lesefortschritt | Fortschrittsbalken in Beiträgen (optional) | Beitragsseite |

### Skripte des Cores im Frontend

| Skript | Zweck | Bedingung |
|---|---|---|
| `assets/dompurify/purify.min.js` + `assets/js/cms-csp-runtime.js` (über `cms_csp_runtime_tags()`) | DOMPurify und Trusted-Types-Policies, erlaubte Skript-Ursprünge | immer, als erste Skripte |
| `assets/js/cookieconsent-init.js` | Consent-Banner, Cookie `cc_cookie` | Modul `legal` + Consent aktiv |
| `assets/js/cms-analytics.js` | consent-gesteuertes Laden von Matomo/GA4/GTM/Pixel | Analytics konfiguriert |
| `assets/js/web-vitals.js` | Core Web Vitals an `/api/v1/analytics/web-vitals` | Web Vitals aktiv |
| `assets/js/photoswipe-init.js` | Lightbox für Galerien/Hub-Seiten | Inhalt mit Galerie |
| `assets/js/site-tables.js` | Suche/Sortierung/Paginierung von Tabellen | Seite mit `[site-table]` |

### Regeln

1. Kein `innerHTML` mit ungeprüften Daten – DOM-APIs (`textContent`, `createElement`) oder Trusted-Types-Policies (`cms365`, `sanitize-html`, `dompurify`) nutzen.
2. Keine `onclick=`-Attribute; Ereignisse per `addEventListener`.
3. Keine externen CDNs ohne Freigabe (`Security::allowCspSources()`) und Consent.
4. Fetch-Aufrufe mit `credentials: 'same-origin'`; schreibende Requests mit CSRF-Token.
5. Progressive Enhancement: Seiten müssen ohne JavaScript benutzbar bleiben (Navigation, Formulare).

### Verwandte Dokumente

[THEME-DEVELOPMENT.md](THEME-DEVELOPMENT.md) · [../core/SECURITY.md](../core/SECURITY.md) · [../assets/js/README.md](../assets/js/README.md)
