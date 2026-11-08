# DOMPurify

> **Stand:** 2026-11-08 | **Version:** 3.4.17 | **Status:** Aktiv

## Kurzbeschreibung

`DOMPurify` bereinigt HTML im Browser. 365CMS nutzt es als Sanitizer der Trusted-Types-`default`-Policy, die die Produktiv-CSP (`require-trusted-types-for 'script'`) voraussetzt.

## Quellordner

- `CMS/assets/dompurify/purify.min.js` (+ `LICENSE`, MPL-2.0 oder Apache-2.0)
- `CMS/assets/js/cms-csp-runtime.js` (365CMS-eigene CSP-Runtime)

## Einbindung

- `cms_csp_runtime_tags()` (`CMS/includes/functions/options-runtime.php`) gibt beide Scripts mit Request-Nonce aus
- als erstes Element nach `<meta charset>` in `admin/partials/header.php`, `member/partials/header.php`, `themes/cms-default/header.php` und `views/auth/cms-auth.php`

## Verhalten der CSP-Runtime

- **createHTML:** jede `innerHTML`/`insertAdjacentHTML`/`DOMParser`-Zuweisung läuft durch DOMPurify (Event-Handler, `javascript:`-URLs, Scripts werden entfernt; `data-*`, `aria-*`, SVG, `iframe`, `target`, `contenteditable` bleiben erhalten). Tabellen-Fragmente (`<tr>`, `<td>` …) werden im passenden Tabellenkontext bereinigt.
- **createScriptURL:** nur same-origin `http(s)`-URLs
- **createScript:** immer blockiert (kein `eval`/`new Function`/`script.text`)
- **Style-Nonce:** per `document.createElement('style')` erzeugte Elemente erhalten den Request-Nonce (Editor.js, Plugins, SunEditor); per HTML eingeschleuste `<style>`-Tags bleiben blockiert
- ohne DOMPurify schlägt jede HTML-Zuweisung fehl (fail closed), statt Inhalte stillschweigend zu leeren

### Erwartete Konsolenmeldungen

- `This document requires 'TrustedScript' assignment …` aus `editorjs/image.umd.js`, `link.umd.js` und `attaches.umd.js`: Die Bundles probieren `new Function("return this")` in einem `try`/`catch` und nutzen danach `window`. Die Werkzeuge funktionieren normal.
- `Executing inline event handler violates … script-src` mit Quelle `purify.min.js`: Chrome meldet `on*`-Attribute, sobald DOMPurify Markup mit Event-Handlern einliest. Der Handler wird nicht ausgeführt, DOMPurify entfernt das Attribut.

## Website / GitHub

- Website: https://cure53.de/purify
- GitHub: https://github.com/cure53/DOMPurify
- npm: https://www.npmjs.com/package/dompurify