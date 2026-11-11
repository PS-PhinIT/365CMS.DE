# Interne JavaScript-Assets

## Kurzbeschreibung

Der Ordner `CMS/assets/js/` enthält interne 365CMS-Skripte für Admin, Frontend-Helfer und Integrationen.

## Quellordner

- `CMS/assets/js/`

## Verwendung in 365CMS

- u. a. `photoswipe-init.js`, `editor-init.js`, `site-tables.js`
- zentrale Ergänzung zu externen Bibliotheken

## Admin-Helfer (seit 4.0.0)

- `admin-sidebar-editor.js` – Editor der Admin-Seitenleiste (Profil „Eigenes Layout“ unter *Einstellungen › Admin-Menü*, siehe [../../admin/ADMIN-MENU.md](../../admin/ADMIN-MENU.md)); nicht verwechseln mit `admin-menu-editor.js` (Menüs des Themes)
- `admin-batch-runner.js` – Browser-Gegenstück zu `BatchJobService`: `window.cmsBatchRunner.create({ container, endpoint, token, stepAction, cancelAction, extra, onDone, onProgress })`, danach `runner.start(jobId)`. Ruft die Schritte nacheinander auf, zeigt den Fortschritt und kann pausieren, fortsetzen und abbrechen (Bestätigung über `cmsConfirm`)
- `cmsToast(type, message, { timeout })` – kurze Rückmeldung unten rechts (`success`, `info`, `warning`, `danger`; `aria-live`), definiert in `admin/partials/footer.php`

## CSP-Infrastruktur

- `cms-csp-runtime.js` – Trusted-Types-`default`-Policy (DOMPurify) und Style-Nonce für dynamisch erzeugte `<style>`-Elemente; wird über `cms_csp_runtime_tags()` als erstes Script im `<head>` geladen (siehe [../dompurify/README.md](../dompurify/README.md))
- `cms-inline-actions.js` – CSP-konformer Ersatz für Inline-Event-Handler in Admin und Member-Bereich:
  - `data-cms-confirm="Text"` (Klick/Submit nur nach Bestätigung)
  - `data-cms-confirm-modal` + `data-cms-confirm-title|message|text|class` (cmsConfirm-Modal, danach Formular absenden)
  - `data-cms-call="fn"` + `data-cms-call-args='[…]'`, `data-cms-call-context="form|element"`, `data-cms-call-on="click|change"` (nur freigegebene Funktionen aus der `CALLABLE`-Liste)
  - `data-cms-enter-call="fn"`, `data-cms-sync-target="id"`
- `tabler-bootstrap-bridge.js` – setzt `window.bootstrap` auf `window.tabler.bootstrap` (Tabler 1.4); direkt nach `tabler.min.js` laden
- `cms-analytics.js` – consent-gesteuerter Loader für GA4/Matomo (Kategorie *analytics*) sowie GTM/Meta Pixel (*marketing*); Konfiguration aus `#cms-analytics-config` (`SeoAnalyticsRenderer`), CSP-Hosts nur für konfigurierte Anbieter (`Security::allowCspSources()` im Hook `cms_csp_prepare`)

## Website / GitHub

- Website: –
- GitHub: –