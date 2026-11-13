# SunEditor

## Kurzbeschreibung

`SunEditor` ist der klassische WYSIWYG-Editor in 365CMS. Standard ist `Editor.js`; SunEditor wird nur noch geladen, wenn `setting_editor_type = suneditor` gesetzt ist.

## Quellordner

- `CMS/assets/suneditor/` – Version **3.4.3** (`suneditor.min.js`, `css/suneditor.min.css`, `lang/de.js`; das nirgends geladene `css/suneditor-contents.min.css` wurde in 3.4.19 entfernt)
- Quelle: npm `suneditor@3.4.3` (`dist/suneditor.min.js`, `dist/suneditor.min.css`, `src/langs/de.js`, unverändert übernommen); `CMS_ASSETS/suneditor-3.4.3.zip` enthält den vollständigen Inhalt dieses npm-Pakets (inkl. `dist/`)
- Registry-Prüfsummen des Tarballs `suneditor-3.4.3.tgz`: SHA-1 `3de265803b44ab353ea6620b8ad8466b4fb94e02`, SHA-512 (npm `integrity`) `sha512-j92HO5IONjqUmFxbu80U0bmgrJ2ALmrpy3JcgXEis97TDAnYRjkXBZcDBkX/gWyhiOoBLlC1kqcUxP2DTIoIFw==`, SHA-256 `50a843ec37053ba56d42b7a72450a2c6351468c33f2956d237026a6b49847fd7`
- SHA-256 der Runtime-Dateien: `suneditor.min.js` `6543eace29c8031817705220014e9b2daed65d33e45de7cfdebbaf970b1d80ca`, `css/suneditor.min.css` `56470575c227b4c0c14a83c50aa54569365f5047f74bc9ee5a41a1a0da5736d3`, `lang/de.js` `d486d416f56ca16c63737087a0034793a091557bdef1dc825e4e0db9048bdf9e`

## Verwendung in 365CMS

- direkte Asset-Einbindung in `CMS/core/Services/EditorService.php`
- Member-Profil (`CMS/member/profile.php`, Feldtyp `wysiwyg`) über `EditorService::render()`, wenn `setting_editor_type = suneditor`
- Weitere Aufrufer gibt es im Core nicht mehr (Hub-Sites und das frühere Plugin `cms-jobprofile-generator` nutzen SunEditor nicht bzw. sind nicht Teil des Repositorys)

## SunEditor-3-API (seit 3.4.18 in allen Aufrufern)

SunEditor 3 ist nicht mehr zur 2.x-Konfiguration kompatibel. Die alten Aufrufe (`setContents()`, `editor.onChange = …`, `buttonList` ohne Plugins) brachen schon mit 3.0.5 ab und fielen auf die nackte Textarea zurück. Alle Aufrufer nutzen jetzt:

- `plugins`: nur die Plugins der Toolbar (`SUNEDITOR.plugins[name]`), alte Namen werden gemappt (`hiliteColor` → `backgroundColor`, `formatBlock` → `blockStyle`)
- `value` für den Startinhalt, `events.onChange(params)` mit `params.data` zum Zurückschreiben in die Textarea (SunEditor 3 synchronisiert die Textarea nicht selbst)
- `$.html.get()` / `$.html.set()` statt `getContents()` / `setContents()`; `EditorService` registriert in `window.cmsLegacyEditors` einen Adapter mit `getContents()`/`setContents()` für `admin-content-editor.js`
- `attributeWhitelist` mit `'*'` statt `all`, `blockStyle.items` statt `formats`, `statusbar_*` statt `resizingBar`/`showPathLabel`
- Die Prüfung `typeof SUNEDITOR` läuft erst nach `DOMContentLoaded`, weil die Skripte mit `defer` geladen werden

## Sicherheitsstand (Update 3.3.3 → 3.4.3, 2026-11-13)

- Anlass: Herstellerhinweise zu Sanitizer-/XSS-Lücken in 3.3.x (behoben ab 3.4.0; 3.4.3 zusätzlich GHSA-j4jp-7r3h-rf28, Code-Block-Sprache, siehe `release-note.md` im Archiv). Im eigenen Vergleich ließ 3.3.3 beim Setzen von Inhalt `<a:1 onclick="…">` samt Handler durch und baute aus `data-se-iframe-holder-attrs` ein iframe mit `srcdoc`-Attribut; 3.4.3 entfernt beides.
- Die CMS-Konfiguration in `EditorService::render()` lässt zusätzlich keine iframes mehr in den Editor: `iframe` ist aus der `attributeWhitelist` entfernt, `elementBlacklist: 'iframe'` gesetzt und die Zwischenablage-Platzhalter `data-se-iframe-holder(-attrs)` stehen in der `attributeBlacklist` (die Toolbar hat kein Embed-/Video-Plugin).
- Geprüft im Chromium (Playwright) mit der von `EditorService` erzeugten Seite, einmal ohne und einmal mit der CMS-CSP samt Trusted-Types-default-Policy: Initialisierung, deutsche Sprachdatei, Toolbar, `onChange`-Sync in die Textarea, `getContents()`/`setContents()`-Adapter und Tabellen/Links funktionieren unverändert; keine neuen Konsolenmeldungen gegenüber 3.3.3. Die Probe-Payloads (`<a:1 onclick>`, `javascript&colon;`, iframe-Platzhalter mit `srcdoc`, nacktes `<iframe>`) hinterlassen weder Handler noch `javascript:`-URL, `srcdoc` oder iframe.
- Serverseitig bleibt `EditorService::sanitize()` und bei der Ausgabe `EditorJsHtmlSanitizer::sanitizeRawBlock()` maßgeblich; der Editor-Sanitizer ist nur die erste Schicht.

## Website / GitHub

- Website: https://suneditor.com/
- GitHub: https://github.com/JiHong88/suneditor