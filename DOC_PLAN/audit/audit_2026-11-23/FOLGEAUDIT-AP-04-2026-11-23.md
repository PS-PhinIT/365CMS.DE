# AP-04 – Ausgabe: XSS, CSP, Vorlagen (Version 4.2.4)

**Stand 23.11.2026.** Geprüft wurde `main` nach `8679a05` (Version 4.2.0, Schema v27), statisch durch Quelltextlesung und
Skriptsuchen über `CMS/views/`, `CMS/themes/cms-default/`, `CMS/admin/views/`, `CMS/member/`, `CMS/assets/js/` und die Rendering-Dienste.
Maßstab: [Audit-Katalog, AP-04](../AUDIT-KATALOG-4.2.0.md#ap-04-ausgabe-xss-csp-vorlagen). Der Bericht betrifft nur AP-04; er ist keine
Freigabe der gesamten Ausgabeschicht. Es wurden keine Browser, Konten oder Produktivsysteme verwendet. Fundstellen sind relativ zur
Repository-Wurzel.

**Ergebnis:** Ein Befund (LOW, Härtung, umgesetzt): `admin.js` setzte den aus einer Tabellenzelle gelesenen Text über `innerHTML` wieder ein.
Alle übrigen Prüfpunkte sind auf den gelesenen Pfaden erfüllt oder als offen mit Prüfvorgehen geführt.

## Prüfpunktmatrix

| ID | Status | Nachweis | Fehlender Nachweis / Folgeschritt |
|---|---|---|---|
| 04.1 Escaping | ja (Stichprobe) | Skriptsuche über Vorlagen, Theme, Admin-Ansichten, Mitgliederbereich und Community: 368 bloße Variablenausgaben (`<?= $var ?>`, `echo $var;`) ohne Escape-Aufruf in der Zeile; nach Abzug sprechender Namen (`…Html`, `…Url`, `…Json`, Zähler) bleiben 28 im Frontend, jede wurde gegen ihre Zuweisung geprüft: feste Texte, Allowlist-Werte (`$btnTarget`, `$dShadowVal`), Zahlen oder bereits bereinigter Inhalt (`$pageContent`, `$pContent`, `$content` der Feed-Einbettung, `$newsletter['form']`). `CMS/themes/cms-default/partials/home-landing.php:528` (`$lpFooterCopyright`) steht in Elementinhalt und wird beim Speichern mit `strip_tags` bereinigt (`CMS/core/Services/Landing/LandingSanitizer.php:84–94`). Marketplace-Links (`CMS/admin/views/plugins/marketplace.php:314`) laufen über `normalizeMarketplaceUrl()` (nur http/https, `PluginMarketplaceModule.php:937–951`). | Admin-Ansichten mit ~340 weiteren Treffern nur in den Dateien mit den meisten Treffern gesichtet; vollständige Durchsicht je Admin-Seite offen. |
| 04.2 Bereinigung beim Ausgeben | ja | Inhalte laufen bei jedem Aufruf durch `Router::prepareRenderableContent()` (`CMS/core/Router.php:1001–1025`) und `EditorService::renderContent()` (`CMS/core/Services/EditorService.php:391–420`): Editor.js-Blöcke über den Renderer, sonstiges HTML über `EditorJsHtmlSanitizer::sanitizeRawBlock()`, Text ohne Tags per `htmlspecialchars`. Landingtexte über `sanitize_html()` zur Ausgabezeit (`home-landing.php:72–85`). | Filter `content_render` (Plugins) liegt nach der Bereinigung; Plugin-Code ist Vertrauensgrenze (AP-09). |
| 04.3 Community-Markdown | offen (bestehende Prüfung) | Rendering über `Community\Support\ContentRenderer` mit CommonMark ohne Roh-HTML und nachgelagertem Purifier; abgedeckt durch die vorhandenen XSS- und Render-Tests der Community. | Korpus erweitern (Katalog-Vorschlag), eigene Durchsicht in AP-11. |
| 04.4 CSP | ja | `CMS/core/Security.php:111–130`: `script-src 'self' 'nonce-…'`, `style-src 'self' 'nonce-…'`, `object-src 'none'`, `frame-ancestors 'none'`, `base-uri 'self'`, `form-action 'self'`; kein `unsafe-inline`/`unsafe-eval` im Code (nur Newsletter-Vorschau mit eigenem `default-src 'none'`-Rahmen, `NewsletterModule.php:310`). | `style-src` und `font-src` erlauben dauerhaft `fonts.googleapis.com`/`fonts.gstatic.com`, auch bei „Lokale Schriften“; das ist eine Datenschutzfrage (AP-17), keine Ausnutzung. |
| 04.5 DOM-Senken | ja (ein Härtungsbefund) | 31 Zuweisungen von `innerHTML`/`insertAdjacentHTML` in eigenem JS gesichtet: Löschen (`= ''`), feste Vorlagen (`admin-users.js:65`, `admin-user-groups.js:63`, `admin-content-editor.js`), Server-HTML aus der Bereinigungskette (Block-Vorschauen, Community-Vorschau `community.js:212`, Menü-Vorschau) und Editor-Bereinigung (`editor-init.js: sanitizeEditableHtml`). Alle laufen zusätzlich durch die Trusted-Types-Standardrichtlinie. **Abweichung:** siehe TMP-AP04-1. | Zuweisungen mit Servertext in Fehlerkästen (`admin.js:738,817`, `formError.innerHTML = message + details`) sind nur sicher, wenn der Server die Texte maskiert; Quelle der Details je Formular prüfen (offen). |
| 04.6 Admin-Ansichten | ja (Stichprobe) | Formular-Eingänge: Werte maskiert, Links nur bei validiertem Typ und Schema (`CMS/admin/views/forms/submissions-detail.php:68–78`). Benutzername, Referer und User-Agent der Protokolle erscheinen maskiert; `referrer_url` und `request_host` werden beim Speichern normalisiert und gekürzt (`CMS/core/Services/RedirectService.php:163–168`). | Alle Protokoll-Ansichten einzeln durchgehen (offen). |
| 04.7 Fehlerausgaben | ja | `display_errors` nur bei `CMS_DEBUG` (`CMS/config/app.php:85–91`); Router und Dienste geben keine Exception-Texte an Besucher aus (Stichprobe `Router.php`, `PublicRouter.php`, `ThemeRouter.php`). | – |
| 04.8 Datenblöcke | ja | Alle `application/ld+json`-Ausgaben verwenden `JSON_HEX_TAG`/`JSON_HEX_AMP`: `SeoSchemaRenderer.php:304–310`, `Community/Http/PublicController.php:679–681`, `MediaFeatureHooks.php:192–196`, `Knowledgebase/Http/PublicController.php:565–570`, `views/downloads/single.php:95`. | – |
| 04.9 Dateiauslieferung | ja | Medien: inline nur Rasterbilder (`MediaDeliveryService.php:18`, SVG ist beim Upload gesperrt, `MediaService.php:23`), alles andere als Download; `nosniff` (`:260`), bereinigter Dateiname (`:848–852`). Downloads und Community-Anhänge senden `Content-Security-Policy: default-src 'none'; sandbox` (`DownloadFrontendController.php:250`, `Community/Http/AttachmentController.php:73`). Die Upload-Allowlist enthält kein HTML/XML. | – |
| 04.10 Vorschauen | offen | Block-Vorschauen und Community-Vorschau laufen über die Bereinigungskette des Servers (siehe 04.5); `/preview/<token>` rendert mit denselben Vorlagen. | Gegenprobe mit präpariertem Entwurf (Skript im Block-HTML) im Browser. |

## Abweichungen

### TMP-AP04-1 · Leerzustand von Tabellen setzt Zellentext als HTML (LOW, umgesetzt)

- **Fundstelle:** `CMS/assets/js/admin.js:510–523` (`initGlobalEmptyTablePattern()`).
- **Ablauf:** Für jede Tabellenzelle mit `colspan`, deren Text mit „Keine … vorhanden/gefunden/…“ beginnt, liest das Skript `cell.textContent` (die Zeichen sind dann bereits entmaskiert), schneidet den Mittelteil als `entity` heraus und setzt ihn per `innerHTML` in `'Keine ' + entity + ' vorhanden.'` ein. Enthält die Zelle Text mit spitzen Klammern, etwa einen Suchbegriff oder Fremdinhalt in einer Meldung „Keine … für „<…>“ gefunden.“, wird er dadurch als HTML ausgewertet.
- **Auswirkung:** DOM-XSS-Muster im Admin. Ein Weg mit tatsächlich ungefiltertem Fremdtext in einer solchen Zelle wurde **nicht** gefunden (Skriptsuche nach Leerzustandszellen mit Variablen: keine Treffer); gebremst durch CSP ohne `unsafe-inline` und die Trusted-Types-Standardrichtlinie. Daher LOW (Härtung).
- **Korrektur:** Der HTML-Baustein bleibt fest; die Beschriftung wird in ein Zielelement per `textContent` gesetzt.
- **Regression:** `TESTS/security/dom-sinks.php` prüft den Quelltext der Funktion (`entity` fließt nicht in `innerHTML`, `textContent` wird gesetzt); ohne die Korrektur schlagen beide Prüfungen fehl. **Status:** umgesetzt. **Konfidenz:** hoch für den Mangel, niedrig für die Erreichbarkeit.

## Geprüft, ohne Befund

- Alle JSON-LD-Blöcke, Dateiauslieferung (Medien, Downloads, Anhänge), Fehlerausgabe, Server-Bereinigung von Beiträgen, Seiten und Landingtexten.
- Keine Inline-Skripte oder Ereignis-Attribute in den geprüften Vorlagen (Prüfung `theme-contract`).

## Nachprüfung zur Laufzeit (24.11.2026)

Mit laufender Seite (PHP-Entwicklungsserver, MariaDB 10.11, Chromium) geprüft: Zwei veröffentlichte Beiträge und zwei freigegebene Kommentare mit Angriffsinhalten in allen
Feldern, die auf der Seite erscheinen. Beitrag 1 im Format Editor.js mit elf Blöcken (Absatz, Überschrift, Liste mit verschachteltem Eintrag, Zitat mit Quelle, Code, Tabelle, Bild mit
Adresse `javascript:`, Linkkarte, Einbettung, Roh-HTML, Schaltfläche), Beitrag 2 als rohes HTML (ältere Inhalte); dazu Titel, Auszug, Meta-Titel, Meta-Beschreibung, Autorname und
Autoradresse (`javascript:`), Kommentarname und -text. Die Nutzlasten: `<img onerror>`, `<svg onload>`, `javascript:`-Links, `<script>`, `<iframe srcdoc>`, `<details ontoggle>`,
`<style>@import`, Formular mit `javascript:`-Ziel, Mutations-XSS (`math`/`mglyph`/`style`), `data:text/html`-Link, `<object data=javascript:>`, `<meta http-equiv=refresh>`, `<base>`,
`<link rel=stylesheet>` auf fremden Host, `autofocus onfocus`, Ausbruch aus Attribut und Skript (`"><script>`).

- **Ergebnis auf `/blog/xss-test`, `/blog/xss-html` und `/blog`:** kein Flag `window.__x*` gesetzt (nichts ausgeführt), keine Elemente mit `on…`-Attribut, keine URLs mit `javascript:`, `vbscript:` oder `data:text/html`, kein `<iframe>`, `<object>`, `<base>`, `<meta http-equiv>`, keine fremde Stylesheet-Einbindung, **keine Anfrage an fremde Hosts**, keine `securitypolicyviolation`-Ereignisse. Im Roh-HTML steht kein `<img … onerror` mehr.
- **Kopfbereich (04.8):** Titel, Beschreibung, Open-Graph- und Twitter-Felder enthalten die Eingabe als `&quot;&gt;&lt;script&gt;…`; das JSON-LD ist gültiges JSON, enthält kein rohes `<` oder `>` und nutzt `\u003C` (eigener Skript-Block, der Ausbruch über `</script>` gelingt nicht).
- **Kommentare:** Name und Text erscheinen als Text, `<b>` und `<img>` nicht als Markup.
- **Wirkung:** 04.1 (Escaping), 04.2 (Bereinigung beim Ausgeben) und 04.8 (Datenblöcke) sind damit nicht nur im Quelltext, sondern am gerenderten Ergebnis bestätigt.
- **Nicht geprüft:** Block-Vorschau im Editor, `/preview/<token>` und die Vorschau der Newsletter-Kampagne (04.10), weil dafür Entwürfe mit Vorschau-Link und der Editor nötig sind.

## Testlauf und Übergabe

PHP 8.3.6 CLI unter Linux, `node --check` für `admin.js`. `php TESTS/security/run.php`: neue Prüfung grün; die bekannte Rotprüfung
des Quellarchivs `CMS_ASSETS` (Umzug in ein privates Repository) behebt PR #194.

**Nächster Schritt:** AP-05 (CSRF, Header, Cookies). Offen aus AP-04: Browser-Gegenprobe der Vorschauen (04.10), Durchsicht aller Protokoll-Ansichten (04.6), Quellen der Fehlerkästen in `admin.js` (04.5).
