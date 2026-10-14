# SunEditor

## Kurzbeschreibung

`SunEditor` ist der klassische WYSIWYG-Editor in 365CMS und bleibt aktiv, obwohl parallel `Editor.js` unterstützt wird.

## Quellordner

- `CMS/assets/suneditor/` – Version **3.3.3** (`suneditor.min.js`, `css/suneditor.min.css`, `lang/de.js`; das nirgends geladene `css/suneditor-contents.min.css` wurde in 3.4.19 entfernt)
- Quelle: npm `suneditor@3.3.3` (`dist/` + `src/langs/de.js`); das GitHub-Archiv `CMS_ASSETS/suneditor-3.3.3.zip` enthält kein `dist/`

## Verwendung in 365CMS

- direkte Asset-Einbindung in `CMS/core/Services/EditorService.php`
- Member-Profil (`CMS/member/profile.php`, Feldtyp `wysiwyg`) über `EditorService::render()`, wenn `setting_editor_type = suneditor`
- Hub-Sites (`CMS/admin/hub-sites.php` + `CMS/assets/js/admin-hub-site-edit.js`) für Beschreibung, Hero-Texte und Kachel-Zusammenfassungen
- Plugin `cms-jobprofile-generator` (`assets/js/jobprofile-admin.js`)

## SunEditor-3-API (seit 3.4.18 in allen Aufrufern)

SunEditor 3 ist nicht mehr zur 2.x-Konfiguration kompatibel. Die alten Aufrufe (`setContents()`, `editor.onChange = …`, `buttonList` ohne Plugins) brachen schon mit 3.0.5 ab und fielen auf die nackte Textarea zurück. Alle Aufrufer nutzen jetzt:

- `plugins`: nur die Plugins der Toolbar (`SUNEDITOR.plugins[name]`), alte Namen werden gemappt (`hiliteColor` → `backgroundColor`, `formatBlock` → `blockStyle`)
- `value` für den Startinhalt, `events.onChange(params)` mit `params.data` zum Zurückschreiben in die Textarea (SunEditor 3 synchronisiert die Textarea nicht selbst)
- `$.html.get()` / `$.html.set()` statt `getContents()` / `setContents()`; `EditorService` registriert in `window.cmsLegacyEditors` einen Adapter mit `getContents()`/`setContents()` für `admin-content-editor.js`
- `attributeWhitelist` mit `'*'` statt `all`, `blockStyle.items` statt `formats`, `statusbar_*` statt `resizingBar`/`showPathLabel`
- Die Prüfung `typeof SUNEDITOR` läuft erst nach `DOMContentLoaded`, weil die Skripte mit `defer` geladen werden

## Website / GitHub

- Website: https://suneditor.com/
- GitHub: https://github.com/JiHong88/suneditor