# Tabler Core

> **Stand:** 2026-10-04 | **Version:** 3.5.0 | **Tabler:** 1.6.1 | **Tabler Icons:** 3.48.0

## Kurzbeschreibung

`Tabler` ist das primäre Admin-UI-Framework von 365CMS.

## Quellordner

- `CMS/assets/tabler/`
- `CMS/assets/tabler-icons/` für die lokal eingebundenen Tabler-Icon-Webfonts

## Verwendung in 365CMS

- direkte CSS-Einbindung in `CMS/admin/partials/header.php`
- direkte JS-Einbindung in `CMS/admin/partials/footer.php`
- seit `3.3.42` lädt der Admin-Header die Tabler Icons nicht mehr über jsDelivr/CDN, sondern ausschließlich lokal über `/assets/tabler-icons/tabler-icons.min.css`

## Update auf Tabler 1.6.1 (3.4.18)

- Übernommen werden nur `dist/css/tabler.min.css` und `dist/js/tabler.min.js` aus dem npm-Paket `@tabler/core@1.6.1` (das GitHub-Archiv `CMS_ASSETS/tabler--tabler-core-1.6.1.zip` enthält kein `dist/`).
- Lokale Anpassung wie schon bei 1.4.0: Der abschließende `sourceMappingURL`-Kommentar wird entfernt, weil die Runtime keine `.map`-Dateien ausliefert (sonst 404 in den Browser-Devtools). Sonst bytegleich zum Upstream-Build.
- JavaScript: Bootstrap 5.3.8 ist in `tabler.min.js` enthalten und unter `window.tabler` bzw. `window.tabler.bootstrap` erreichbar. `js/tabler-bootstrap-bridge.js` setzt daraus weiterhin `window.bootstrap`, sodass alle `bootstrap.Modal`/`Tooltip`-Aufrufe in Core und Plugins unverändert funktionieren.
- Sichtbare Änderungen aus 1.5/1.6: System-Schriftstapel statt „Inter“ (`--tblr-font-sans-serif`), Farben in `oklch()`, neue neutrale Grauskala, Fokus-Ring als `outline`. Die `--tblr-*-rgb`-Variablen (z. B. `--tblr-primary-rgb` in `admin*.css`) sind deprecated, bleiben aber bis Tabler 2.0 erhalten.

## Lokale Icon-Webfonts

Die Webfont-Dateien aus `CMS/assets/tabler-icons/fonts/` (Stand 3.48.0 aus npm `@tabler/icons-webfont`, bytegleich; keine Icons gegenüber 3.41.1 entfernt) sind produktiver Runtime-Bestand. Sie ersetzen den früheren externen Tabler-Icons-CDN-Request im Admin und müssen bei Asset-Refreshes gemeinsam mit `tabler-icons.min.css` erhalten bleiben.

## Website / GitHub

- Website: https://tabler.io/
- GitHub: https://github.com/tabler/tabler
- Tabler Icons (`CMS/assets/tabler-icons/`): https://tabler.io/icons / https://github.com/tabler/tabler-icons