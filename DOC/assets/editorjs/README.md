# Editor.js

## Kurzbeschreibung

`Editor.js` ist der blockbasierte Editor für moderne Inhaltsbearbeitung in 365CMS.

## Quellordner

- Originalquelle: offizieller Build `dist/editorjs.umd.js` aus npm `@editorjs/editorjs@2.31.7` (Quellarchiv: `CMS_ASSETS/editor.js-2.31.7.zip`, enthält kein `dist/`)
- Runtime-Ziel: `CMS/assets/editorjs/editorjs.umd.js`

## Verwendung in 365CMS

- Asset-Management in `CMS/core/Services/EditorJsService.php`
- Rendering in `CMS/core/Services/EditorJsRenderer.php`
- Nutzung über Theme-/Frontend-Integration

## Verfügbare Tools (Stand 25.05.2026)

- Aktive Page/Post-Basis-Tools: `paragraph`, `header`, `list` (inkl. `checklist`-Style), `image`, `quote`, `code`, `table`, `delimiter` mit `line`/`dash`/`star`-Varianten und `spacer` mit `10px`, `15px`, `25px`, `40px`, `60px`, `75px`, `100px`, `150px` und `200px` in der Auswahl.
- Zusätzlich aktivierte lokale Erweiterungen: `embed`, `linkTool`, `attaches`, `warning`, `alert`, `raw`, `accordion`, `imageGallery`, `mediaText` sowie Inline-Tools `inlineCode`, `underline`, `strikethrough`, `hyperlink`, `marker`, `spoiler`, `textColor`.
- Produktive Editor-Plugins: `editorjs-undo` für Undo/Redo inkl. Toolbar-Buttons und `editorjs-drag-drop` für Block-Reordering per Drag&Drop. Beide werden lokal als UMD-Dateien geladen und defensiv initialisiert.
- Der Admin-Editor bietet eine WordPress/Gutenberg-ähnlichere Oberfläche: Commandbar mit Block-Inserter, Undo/Redo, Breitenmodus und gruppierte Blockkarten für Text, Medien sowie Layout/Spezialblöcke. Aktive Blocktools sind in der Page/Post-GUI und im generischen `EditorJsAssetService` als Schnellwerkzeuge erreichbar; optionale Inline-Erweiterungen liegen in der Textformatierungsbubble bzw. in den nativen EditorJS-Tune-Menüs.
- Hinweis-/Warnboxen werden seit `3.0.15` über das lokale `CmsWarningTool` gerendert: Titel und Inhalt sind contenteditable, unterstützen sichere Inline-Formatierungen und können als `Info`, `Warnung`, `Erfolg` oder `Kritisch` gespeichert werden.
- Nachtrag 19.05.2026: Die lokale Tool-Schicht unterstützt Read-only-Kontexte defensiver, sodass Vorschau- und geschützte Ansichten nicht mehr von editierbaren UI-Annahmen abhängen.
- Der Core wird bytegleich aus dem offiziellen 2.31.7-Build in `CMS/assets/editorjs/editorjs.umd.js` bereitgestellt. Der bisherige 2.31.6-Core war ebenfalls unverändert; lokale 365CMS-Anpassungen stecken ausschließlich in den Plugin-Bundles (z. B. `hyperlink.umd.js`, lokal gebautes `delimiter.umd.js`) und in `CMS/assets/js/editor-init.js`, die vom Core-Update unberührt bleiben.
- Die Page/Post-Tools werden als lokale UMD-Dateien aus `CMS/assets/editorjs/` geladen: Core, Basis-Tools und stabile Erweiterungen werden deterministisch vor `CMS/assets/js/editor-init.js` eingebunden.
- `CMS/assets/js/editor-init.js` ist nur noch die 365CMS-Factory/Normalizer-Schicht: Sie verdrahtet die UMD-Globals (`Paragraph`, `Header`, `EditorjsList`, `ImageTool`, `Quote`, `CodeTool`, `Table`, `Delimiter`, `Embed`, `LinkTool`, `AttachesTool`, `Warning`, `RawTool`, `Accordion`/`AccordionBlock`, `CmsImageGalleryTool`, `InlineCode`, `Underline`, `Strikethrough`, `Hyperlink`, `TgSpoilerEditorJS`, `ColorPlugin`) sowie lokale Factory-Tools wie `CmsMarkerTool` und die Plugin-Globals (`Undo`, `DragDrop`) mit Upload-, Save-, History- und Legacy-Datenkompatibilität.
- Plugin-Registrierung ist defensiv: optionale Tools werden nur aktiviert, wenn ihr lokales UMD-Global tatsächlich vorhanden ist. Dadurch gibt es keine toten Toolbar-Buttons und keine parallelen Modul-/Eval-Loader.

## WordPress-like Block-/Blockly-Verhalten

Der Admin-Editor ist als redaktioneller Block-Canvas konzipiert, nicht als technischer JSON-Editor. Das Verhalten orientiert sich an Gutenberg-/WordPress-Mustern, bleibt aber vollständig EditorJS-basiert:

- **Block-Inserter:** Blöcke werden über gruppierte Karten eingefügt (`Text`, `Medien`, `Layout`, `Spezial`) statt über eine lange technische Tool-Liste.
- **Commandbar:** Häufige Aktionen wie Einfügen, Undo/Redo und Breitenmodus sind direkt oberhalb des Canvas erreichbar.
- **Drag & Drop:** `editorjs-drag-drop` übernimmt Block-Reordering; der Save-Pfad bleibt bei strukturierter EditorJS-JSON-Ausgabe.
- **Undo/Redo:** `editorjs-undo` ergänzt die gewohnte Redaktionskorrektur ohne zusätzliche Serverzustände.
- **Breitenmodus und Canvas-Typografie:** Der Editor kann kompakter oder breiter wirken und übernimmt relevante Theme-Typografie für eine näher am Frontend liegende Bearbeitungsansicht.
- **Read-only-fähige Tools:** Vorschau- und geschützte Kontexte initialisieren Tools defensiv, damit reine Ansichten nicht durch editierbare UI-Annahmen brechen.

Damit entsteht ein WordPress-ähnliches Blockgefühl, während Sanitizer, Renderer und Theme-CSS weiterhin den sicheren 365CMS-Datenvertrag erzwingen.

## Save-/Render-/Sanitizer-Vertrag

- Neue und bestehende Blöcke werden serverseitig über `EditorJsSanitizer` validiert/sanitized; unbekannte oder ungültige Typen werden verworfen.
- Das Frontend rendert über `EditorJsRenderer` typ-spezifisch und sanitizt Inline-/Raw-Inhalte erneut.
- Nachtrag 25.05.2026: `EditorJsSanitizer` normalisiert Spacer-Höhen aus Presets und Pixelwerten in einen begrenzten Bereich bis `200px`; `10px`, `150px` und `200px` sind explizit im Client, Sanitizer und Public-Renderer erlaubt. Ungültige Werte fallen auf sichere Defaults zurück.
- Spacer-Blöcke werden im Public-HTML mit kontrolliertem `data-height`, `role="presentation"` und `aria-hidden="true"` ausgegeben; der zentrale Purifier erlaubt diese Attribute, damit Themes die gespeicherte Höhe per CSS-Fallback sichtbar abbilden können.
- Das globale Public-Stylesheet `CMS/assets/css/editorjs-content.css` lädt im Frontend seit `3.0.21` nicht mehr render-blockierend: `CMS/core/Bootstrap.php` injiziert einen kleinen Inline-Basisstil für Blöcke, Medien, Tabellen und Spacer und lädt die vollständige CSS anschließend per `preload`/`onload` mit `noscript`-Fallback nach.
- Themes, die eigene Rich-Content-Abstände setzen, müssen `.editorjs-spacer[data-height]` aus generischen Absatz-/Block-Margins ausnehmen und die Höhe explizit über `height`/`min-height` oder eine CSS-Variable respektieren.
- Bild- und Galerieblöcke behalten Darstellungsoptionen wie Ausrichtung, Größe, Skalierungsmodus, Maxhöhe, Rahmen, Hintergrund, Rundung und Schatten über normalisierte Datenattribute, damit Themes gezielt stylen können, ohne unsichere HTML-Fragmente zu übernehmen.
- Hinweis-/Warnboxen speichern `variant`, `title` und `message`; Titel und Nachricht laufen durch denselben Inline-Sanitizer wie Textblöcke, sodass erlaubte Markups wie `<strong>`, `<em>`, `<u>`, `<code>`, sichere Links und Spoiler erhalten bleiben.
- Delimiter-Blöcke speichern nur erlaubte Stilwerte (`line`, `dash`, `star`) sowie begrenzte Linienbreiten/-stärken; Hyperlink- und Strikethrough-Inline-Markups werden client- und serverseitig auf sichere Tags, `href`-Schemata sowie `target`/`rel`-Tokens reduziert.
- Legacy-Inhalte (JSON-String, HTML-Fallback, Plaintext) werden clientseitig in `editor-init.js` rückwärtskompatibel in Blockdaten normalisiert.
- Bild-Uploads laufen weiterhin über den bestehenden `/api/media?action=upload_image`-Flow inkl. CSRF-Header; normale Bildblöcke können zusätzlich die vorhandene Mediathek-Auswahl (`list_images`) nutzen und übernehmen URL sowie vorhandene Caption/Alt-Beschreibung in die Vorschau. Reine Dateinamen werden nicht mehr als sichtbare Caption/Alt-Text vorbelegt.
- Page-/Post-Uploads reichen den Editor-Kontext (`content_type`, Slug-/Titel-Fallbacks, `draft_key`) an `/api/media` weiter, damit Bilder direkt in `uploads/articles/...`, `uploads/pages/...` oder temporäre Draft-Ordner einsortiert werden.
- Die lokalen 365CMS-Tools definieren ergänzende Editor.js-Client-Sanitizer, Paste-Substitutionen für Bilder/Bild-URLs sowie Read-only-Support; serverseitige Validierung bleibt verbindlich.
- Die lokale Galerie unterstützt Mehrfachupload, Mediathek-Auswahl, Caption-Pflege, stabile Bilddaten-Normalisierung und Sortierung per `Hoch`/`Runter`, ohne zusätzliche SortableJS-Abhängigkeit. Beim Admin-Reload dedupliziert der Client parallel vorhandene `images`-/`urls`-Legacy-Felder nach URL, damit Galeriebilder nach erneutem Speichern nicht anwachsen.
- Medienblock-Eigenschaften für `image`, `imageGallery` und `mediaText` werden im Admin als dezente linke Properties-Leiste gerendert. Sie liegen dadurch nicht mehr als Overlay über Bildvorschau oder Textfläche und bleiben auf mobilen Viewports gestapelt bedienbar.
- `mediaText`/Text+Bild-Blöcke bieten eine Option „Dezenter Rahmen“. Aktivierte Rahmen werden mit 1px Kontur, maximal 2px Rundung und konsistentem 10px Abstand oben/unten im Admin und Public-Renderer ausgegeben.
- `mediaText`/Text+Bild-Blöcke unterstützen zusätzlich eine optionale Überschrift sowie getrennte Abstände oberhalb und unterhalb des Blocks. Die optionale Überschrift wird semantisch als H4 gerendert und nutzt in Admin-Vorschau sowie Public-Ausgabe den H4-Maßstab. Ist „Dezenter Rahmen“ aktiv, wird sie als angedocktes Band am oberen Rahmen gerendert.
- Bildskalierungs-Nachtrag 25.05.2026: Normale Bildblöcke speichern `imageFit` und `maxHeight` über kontrollierte Auswahlwerte; Text+Bild-Blöcke speichern `imageFit`. Erlaubt sind `contain`, `cover`, `fill`, `scale-down` und `none`, Maxhöhen sind auf sichere Presets bis `1000px` begrenzt und werden im Public-Renderer sowie in Admin-/Public-CSS konsistent angewendet.
- Clipboard-Nachtrag 25.05.2026: Wird im Textbereich eines `mediaText`-/Text+Bild-Blocks kombinierter Inhalt aus Bild und formatiertem Text eingefügt, übernimmt der Block beides im selben Datensatz. Bilddateien aus der Zwischenablage laufen durch den normalen Upload, HTML-`img`-Quellen werden als sichere URL gesetzt und der Textteil wird ohne das Bild in den Contentbereich eingefügt.
- Audit-Nachtrag 25.05.2026: HTML-Zwischenablagen, die ein eingebettetes `data:image/...;base64,...` statt eines echten Clipboard-Files liefern, werden für `mediaText` nun in sichere `File`-Objekte konvertiert und über denselben Uploadpfad verarbeitet. Erlaubt bleiben ausschließlich die EditorJS-Bildtypen bis 25 MB.
- Audit-Nachtrag 25.05.2026: Das alte externe `ImageGallery`-Bundle wird nicht mehr geladen, weil die aktive Galerie ausschließlich über das lokale `CmsImageGalleryTool` läuft. Datei-Anhänge besitzen jetzt neben `uploadByFile` auch ein explizites `uploadByUrl`; MediaText-Überschriften nutzen PHP-seitig einen `mb_substr`-Fallback.
- Audit-Abschluss 25.05.2026: Core-Factory, Tool-/Asset-Registry, Upload-Vertrag, Persistenz, Cleanup und Public-Rendering wurden erneut vollständig abgeglichen. Die interne `editor-init.js` Runtime-/Debug-Version wurde auf den aktuellen Core-Stand `3.3.17` gehoben, damit Browser- und Diagnose-Ausgaben keine veraltete Audit-Version mehr melden.
- Public-Fix 25.05.2026 (`3.3.26`): `mediaText`-Abstände oben/unten setzen neben den blockeigenen Variablen auch robuste Klassen wie `editorjs-media-text--spacing-top-30` und `editorjs-media-text--spacing-bottom-30`. Core-Critical-CSS und `editorjs-content.css` nehmen MediaText aus den pauschalen `.editorjs-block + .editorjs-block`-/`:first-child`-Nullabständen aus; der Normalizer liest die Klassen als Fallback, falls `style` oder `data-spacing-*` in Theme-/Sanitizer-Pfaden reduziert wurden.
- Große Seiten und Beiträge werden im Admin seit `3.3.10` ressourcenschonender geöffnet: Bildvorschauen, Galerietumbnails und Mediathek-Kacheln nutzen `loading="lazy"`/`decoding="async"`, identische Preview-URLs werden nicht erneut zugewiesen, Offscreen-Blöcke werden per `content-visibility` geschont und die eigene Inline-Einfüge-UI erzeugt ab sehr vielen Blöcken keine zusätzlichen Zwischenbutton-/Hover-Overlay-Massen mehr.
- Native EditorJS-Zahnrad-/Popover-Menüs besitzen im Admin eine hohe Stacking-Ebene mit sichtbarem Overflow im Editor-Rahmen. Dadurch bleiben Block-Einstellungen auch bei langen Medien-/Textstrecken vor nachfolgenden Blöcken anklickbar.
- Asset-Bereinigung 25.05.2026: Der Runtime-Ordner `CMS/assets/editorjs/` enthält nur noch registrierte Core-/Tool-/Tune-/Plugin-Bundles. Nicht geladene Bundles wie `checklist.umd.js`, `carousel.umd.js`, `columns.umd.js`, der alte `editorjs.mjs`-Duplicate sowie Cropper-/Drawing-Dateien wurden entfernt. Public-Renderer und Sanitizer behalten Legacy-Support für alte gespeicherte Carousel-/Columns-/Drawing-Daten, laden dafür aber keine Editor-GUI-Bundles mehr.
- UX-Feinschliff 25.05.2026: Blockrahmen im Admin erscheinen nur beim Hover; Fokus/Selection bleiben mit minimalem Hintergrund sichtbar. Dadurch ähnelt der Editor-Canvas stärker der Public-Ausgabe und behält dennoch genug Orientierung für längere Inhalte.
- Audit-Nachtrag 25.05.2026: `createCmsEditor()` reicht `onReady`, `onChange` und `onError` konsistent durch. Der generische `EditorJsAssetService` synchronisiert Hidden-JSON nicht erst beim Submit, sondern auch bei Editor-Änderungen, und zerstört die Instanz bei `pagehide` defensiv.
- Audit-Nachtrag 25.05.2026 (`3.3.21`): Der generische `EditorJsAssetService` und das Page/Post-Binding übergeben nun explizite `onReady`-Callbacks an `createCmsEditor()`. Damit ist der Constructor-Vertrag aus Holder, Daten, Tools, `onReady`, `onChange` und `onError` in allen EditorJS-Einstiegspunkten konsistent verdrahtet.
- Paste-Fix 25.05.2026 (`3.3.22`): Reiner formatierter HTML-Inhalt im Textbereich eines `mediaText`-/Text+Bild-Blocks wird nun direkt an der Cursorposition eingefügt. Listen, Absätze und erlaubte Inline-Formatierungen bleiben im bestehenden Block erhalten; nur externe Block-Editables ohne eigenen Handler nutzen weiterhin den generischen strukturierten Paste-Pfad.
- Audit-Fix 25.05.2026 (`3.3.23`): Der Page/Post-Submit wartet jetzt vor jedem Editor-Save zuerst auf ausstehende Lazy-Bindings. Das gilt auch, wenn für einen Hidden-/Sprach-Editor noch keine EditorJS-Instanz im lokalen Registry-Objekt existiert, aber bereits eine Aktivierung, Kopie oder Übersetzungsübernahme läuft.
- Public-Fix 25.05.2026 (`3.3.24`): Der Renderer blendet bereits gespeicherte dateinamenartige Captions bei Bild-, Galerie- und Carousel-Blöcken aus, damit Grafik-/Dateinamen nicht mehr als sichtbare Bildunterschrift erscheinen.
- Audit-Fix 25.05.2026 (`3.3.25`): `linkTool` sendet den `X-CSRF-Token` nun auch bei Metadaten-GETs an `/api/media?action=fetch_link`; außerdem erkennt der Content-Normalizer bereits gerendertes `.editorjs-media-text`- und `.editorjs-gallery`-HTML, damit doppelte Render-/Theme-Pipelines Bild+Text/Galerien nicht mehr in Einzelblöcke zerlegen.
- Audit-Fix 25.05.2026 (`3.3.27`): `createCmsEditor()` serialisiert debounced `onChange`-Saves nun über eine interne Queue. Änderungen, die während eines laufenden `editor.save()` eintreffen, werden nach dem aktuellen Save erneut gespeichert; dadurch kann ein langsamer älterer Save keinen neueren Hidden-JSON-Stand mehr überschreiben. `destroy()` fängt zusätzlich asynchrone Rejections ab, damit Pagehide-/Unmount-Cleanup keine unbehandelten Promise-Rejections erzeugt.
- Audit-Fix 25.05.2026 (`3.3.31`): Der LinkTool-Metadatenpfad liest bei GET-Requests jetzt auch die Query-Parameter des EditorJS-Plugins aus. Checklisten werden im Admin nicht mehr zu normalen Listen zurückgespeichert; `[x]`/`[ ]`-Präfixe bleiben als `meta.checked` erhalten, und Legacy-`checklist`-Blöcke werden in Normalizer/Sanitizer als kanonische `list`-Payloads mit `style: checklist` erhalten. Der generische `EditorJsAssetService` schreibt dynamische Holder-, Upload- und Tokenwerte in Inline-Scripten als JSON-Literale, damit HTML- und JavaScript-Kontext sauber getrennt bleiben.

## Bekannte Grenzen

- ToC wird aktuell über `header`-Blöcke/Anker im Frontend-Kontext aufgebaut; Themes müssen finale Sanitizer-Stufen so verdrahten, dass Heading-IDs erhalten bleiben oder danach stabil neu gesetzt werden. Ein separater ToC-Editorblock ist noch nicht vorhanden.
- Externe Embed-Provider werden aus Sicherheitsgründen als sichere Link-Embeds (statt unsandboxed iFrame-HTML) ausgegeben.
- Fallback-Textareas in Page/Post-Edit-Views sind hidden/disabled und werden nur eingeblendet, wenn die EditorJS-Initialisierung oder Readiness wirklich fehlschlägt.

## Sicherheits- und Betriebsvertrag

- Gebündelte Core-Version: `Editor.js 2.31.7` (Upstream-Stand 2026-10-04: `v2.31.7`).
- 2.31.7 löst `onChange` jetzt auch bei Änderungen nativer `<select>`-Felder aus. Die Eigenschaften-Selects der lokalen Tools (`image`, `imageGallery`, `mediaText`, Spacer) synchronisierten bisher nur über den eigenen `change`-Listener am Holder (`createCmsEditor()`); beide Wege laufen in denselben entprellten `syncEditorChange()` und erzeugen keinen doppelten Save. Der Holder-Listener bleibt für Checkboxen und Eingabefelder nötig.
- Gespeicherte Editor.js-JSON-Payloads werden serverseitig über `CMS\Services\EditorJs\EditorJsSanitizer` bereinigt; Client-Sanitizer der Tools sind nur Ergänzung.
- Inline-HTML und Raw-Blöcke laufen über `CMS\Services\EditorJs\EditorJsHtmlSanitizer`: keine Event-Attribute, keine `javascript:`-Links, kontrollierte Link-/Asset-Schemata und strikt erlaubte Tags.
- Der Frontend-Renderer sanitizt Raw-Blöcke erneut vor der Ausgabe, damit ältere oder importierte Inhalte nicht ungefiltert gerendert werden.
- Editor.js-Media-Requests bleiben login-/capability- und CSRF-geschützt; technische Fehlerdetails werden serverseitig geloggt und nicht als JSON-Fehlermeldung ausgegeben.
- Bildpicker und Remote-Bildimport erlauben nur Formate, die die zentrale Medienvalidierung als sichere Bild-Uploads unterstützt (`jpg`, `jpeg`, `png`, `gif`, `webp`, `bmp`, `ico`). SVG und AVIF bleiben im Editor.js-Picker/Remote-Import deaktiviert, solange die zentrale Upload-/Derivative-Pipeline sie nicht vollständig validiert.
- Legacy-/Fallback-Submits nach `editor.save()` nutzen native Submitter bzw. `requestSubmit()` statt direkter `form.submit()`-Bypässe.

## Website / GitHub

- Website: https://editorjs.io/
- GitHub: https://github.com/codex-team/editor.js

### Plugin-Upstreams (Stand 2026-10-04)

| Runtime-Datei | npm-Paket | GitHub |
|---|---|---|
| `paragraph.umd.js` | [`@editorjs/paragraph`](https://www.npmjs.com/package/@editorjs/paragraph) | https://github.com/editor-js/paragraph |
| `header.umd.js` | [`@editorjs/header`](https://www.npmjs.com/package/@editorjs/header) | https://github.com/editor-js/header |
| `editorjs-list.umd.js` | [`@editorjs/list`](https://www.npmjs.com/package/@editorjs/list) | https://github.com/editor-js/list |
| `image.umd.js` | [`@editorjs/image`](https://www.npmjs.com/package/@editorjs/image) | https://github.com/editor-js/image |
| `quote.umd.js` | [`@editorjs/quote`](https://www.npmjs.com/package/@editorjs/quote) | https://github.com/editor-js/quote |
| `code.umd.js` | [`@editorjs/code`](https://www.npmjs.com/package/@editorjs/code) | https://github.com/editor-js/code |
| `table.umd.js` | [`@editorjs/table`](https://www.npmjs.com/package/@editorjs/table) | https://github.com/editor-js/table |
| `delimiter.umd.js` | [`@coolbytes/editorjs-delimiter`](https://www.npmjs.com/package/@coolbytes/editorjs-delimiter) | https://github.com/CoolBytesIN/editorjs-delimiter |
| `embed.umd.js` | [`@editorjs/embed`](https://www.npmjs.com/package/@editorjs/embed) | https://github.com/editor-js/embed |
| `link.umd.js` | [`@editorjs/link`](https://www.npmjs.com/package/@editorjs/link) | https://github.com/editor-js/link |
| `attaches.umd.js` | [`@editorjs/attaches`](https://www.npmjs.com/package/@editorjs/attaches) | https://github.com/editor-js/attaches |
| `warning.umd.js` | [`@editorjs/warning`](https://www.npmjs.com/package/@editorjs/warning) | https://github.com/editor-js/warning |
| `alert.umd.js` | [`editorjs-alert`](https://www.npmjs.com/package/editorjs-alert) | https://github.com/vishaltelangre/editorjs-alert |
| `raw.umd.js` | [`@editorjs/raw`](https://www.npmjs.com/package/@editorjs/raw) | https://github.com/editor-js/raw |
| `inline-code.umd.js` | [`@editorjs/inline-code`](https://www.npmjs.com/package/@editorjs/inline-code) | https://github.com/editor-js/inline-code |
| `underline.umd.js` | [`@editorjs/underline`](https://www.npmjs.com/package/@editorjs/underline) | https://github.com/editor-js/underline |
| `strikethrough.umd.js` | [`@sotaproject/strikethrough`](https://www.npmjs.com/package/@sotaproject/strikethrough) | https://github.com/sotaproject/strikethrough |
| `hyperlink.umd.js` | [`editorjs-hyperlink`](https://www.npmjs.com/package/editorjs-hyperlink) | https://github.com/trinhtam/editorjs-hyperlink |
| `text-color.umd.js` | [`editorjs-text-color-plugin`](https://www.npmjs.com/package/editorjs-text-color-plugin) | https://github.com/flaming-cl/editorjs-text-color-plugin |
| `spoiler.umd.js` | [`@iizotikov/editor-js-tg-spoiler`](https://www.npmjs.com/package/@iizotikov/editor-js-tg-spoiler) | https://github.com/izotikov/editor-js-tg-spoiler |
| `anchor.umd.js` | [`editorjs-anchor`](https://www.npmjs.com/package/editorjs-anchor) | https://github.com/VolgaIgor/editorjs-anchor |
| `alignment-tune.umd.js` | [`editorjs-text-alignment-blocktune`](https://www.npmjs.com/package/editorjs-text-alignment-blocktune) | https://github.com/kaaaaaaaaaaai/editorjs-alignment-blocktune |
| `indent-tune.umd.js` | [`editorjs-indent-tune`](https://www.npmjs.com/package/editorjs-indent-tune) | https://github.com/sebmeister2077/editorjs-indent-tune |
| `text-variant-tune.umd.js` | [`@editorjs/text-variant-tune`](https://www.npmjs.com/package/@editorjs/text-variant-tune) | https://github.com/editor-js/text-variant-tune |
| `accordion.umd.js` | [`editorjs-collapsible-block`](https://www.npmjs.com/package/editorjs-collapsible-block) | https://github.com/sebmeister2077/editorjs-accordion |
| `undo.umd.js` | [`editorjs-undo`](https://www.npmjs.com/package/editorjs-undo) | https://github.com/kommitters/editorjs-undo |
| `drag-drop.umd.js` | [`editorjs-drag-drop`](https://www.npmjs.com/package/editorjs-drag-drop) | https://github.com/kommitters/editorjs-drag-drop |

Hinweis: `delimiter.umd.js` stammt aus `@coolbytes/editorjs-delimiter` (Varianten `line`/`dash`/`star`), nicht aus `@editorjs/delimiter`; die Datei entspricht dem npm-Build 1.0.4 (`dist/bundle.js` ohne `sourceMappingURL`-Kommentar, Abgleich 2026-11-13).