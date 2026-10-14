# ASSET-Übersicht 365CMS
> **Stand:** 2026-10-04 | **Version:** 3.5.0 | **Status:** Aktuell

## Inhaltsverzeichnis
- <a>Aktive Runtime-Bundles</a>
- <a>Synchronisationsregeln</a>
- <a>CSS- und JavaScript-Architektur</a>
- <a>Build- und Update-Workflow</a>
- <a>Interne Bild-Assets</a>
- <a>Neue Kandidaten außerhalb der Runtime</a>

---

## Aktive Runtime-Bundles <!-- UPDATED: 2026-10-04 -->
Führende Quelle für **aktive Laufzeitpfade** ist `CMS/assets/` sowie der dokumentierte Sonderfall `CMS/vendor/dompdf/`. Das Root-Verzeichnis `CMS_ASSETS/` (Upstream-Archive als ZIP, früher `ASSETS/`) ist **Staging-/Quellmaterial**, nicht die produktive Wahrheit.

| Library | Runtime-Stand | Zweck | Produktiver Pfad | Quelle (Staging) | Hinweis | Website | GitHub |
|---|---|---|---|---|---|---|---|
| `tabler` | `1.6.1` | Admin-/Member-UI | `CMS/assets/tabler/` | `CMS_ASSETS/tabler--tabler-core-1.6.1.zip` (Quellarchiv) bzw. npm `@tabler/core@1.6.1` `dist/` | nur `css/tabler.min.css` und `js/tabler.min.js` übernehmen, jeweils ohne abschließenden `sourceMappingURL`-Kommentar (keine `.map`-Dateien in der Runtime); Flags/Payments/Marketing/`img/`/`libs/` werden nicht geladen | [tabler.io](https://tabler.io/) | [tabler/tabler](https://github.com/tabler/tabler) |
| `editorjs` | `2.31.7` | Block-Editor | `CMS/assets/editorjs/` | `CMS_ASSETS/editor.js-2.31.7.zip` (Quellarchiv) bzw. npm `@editorjs/editorjs@2.31.7` `dist/editorjs.umd.js` | kuratierter Runtime-Satz aus Core-Dateien und gezielt gebauten Plugin-Artefakten wie `delimiter.umd.js` | [editorjs.io](https://editorjs.io/) | [codex-team/editor.js](https://github.com/codex-team/editor.js) |
| `suneditor` | `3.3.3` | Legacy-WYSIWYG | `CMS/assets/suneditor/` | `CMS_ASSETS/suneditor-3.3.3.zip` (Quellarchiv ohne `dist/`) bzw. npm `suneditor@3.3.3` | Runtime wird aus `dist/` + `src/langs/de.js` übernommen; fehlt `dist/` nach Upstream-Download, muss lokal gebaut werden | [suneditor.com](https://suneditor.com/) | [JiHong88/suneditor](https://github.com/JiHong88/suneditor) |
| `photoswipe` | `5.x`-Build | Lightbox | `CMS/assets/photoswipe/` | `ASSETS/PhotoSwipe/` | nur produktive Frontend-Dateien übernehmen | [photoswipe.com](https://photoswipe.com/) | [dimsemenov/PhotoSwipe](https://github.com/dimsemenov/PhotoSwipe) |
| `Carbon` | `3.14.2` | Datum / Zeit | `CMS/assets/Carbon/src/Carbon/`, `CMS/assets/Carbon/lazy/Carbon/` | `CMS_ASSETS/Carbon-3.14.2.zip` (`src/Carbon/`, `lazy/`) | Upstream-Layout `src/` + `lazy/` beibehalten (relative `lazy/`-Requires); ohne `Laravel/`, `PHPStan/`, `Cli/` | [carbonphp.github.io/carbon](https://carbonphp.github.io/carbon/) | [CarbonPHP/carbon](https://github.com/CarbonPHP/carbon) |
| `ldaprecord` | `4.0.8` | LDAP / Active Directory | `CMS/assets/ldaprecord/` | `CMS_ASSETS/LdapRecord-4.0.8.zip` (`src/`) | kompletter Source-Ordner für PSR-4, ohne `Testing/` | [ldaprecord.com](https://ldaprecord.com/) | [DirectoryTree/LdapRecord](https://github.com/DirectoryTree/LdapRecord) |
| `mailer` | `8.1.7` | Mailversand | `CMS/assets/mailer/` | `CMS_ASSETS/mailer-8.1.7.zip` | Symfony-Komponente | [symfony.com/components/Mailer](https://symfony.com/components/Mailer) | [symfony/mailer](https://github.com/symfony/mailer) |
| `mime` | `8.1.7` | MIME / Anhänge | `CMS/assets/mime/` | `CMS_ASSETS/mime-8.1.7.zip` | Symfony-Komponente | [symfony.com/components/Mime](https://symfony.com/components/Mime) | [symfony/mime](https://github.com/symfony/mime) |
| `translation` | `8.1.5` | i18n | `CMS/assets/translation/` | `CMS_ASSETS/translation-8.1.5.zip` | Symfony-Komponente, ohne Command/DataCollector/DI/Extractor | [symfony.com/components/Translation](https://symfony.com/components/Translation) | [symfony/translation](https://github.com/symfony/translation) |
| `yaml` | `8.1.8` | YAML-Parser (Sprachkataloge) | `CMS/assets/yaml/` | `CMS_ASSETS/yaml-8.1.8.zip` | ohne `Command/` | [symfony.com/components/Yaml](https://symfony.com/components/Yaml) | [symfony/yaml](https://github.com/symfony/yaml) |
| `clock` | `8.1.0` | Clock für Carbon / AI | `CMS/assets/clock/` | `CMS_ASSETS/symfony-8.1.8.zip` (`src/Symfony/Component/Clock/`) | inkl. `Resources/now.php` (files-Autoload) | [symfony.com/components/Clock](https://symfony.com/components/Clock) | [symfony/clock](https://github.com/symfony/clock) |
| `egulias-email-validator` | `4.0.4` | E-Mail-Validierung | `CMS/assets/egulias-email-validator/` | Packagist `egulias/email-validator` | Pflicht für `Mime\Address`/SMTP; benötigt `doctrine-lexer` 3.0.2 | [packagist.org/packages/egulias/email-validator](https://packagist.org/packages/egulias/email-validator) | [egulias/EmailValidator](https://github.com/egulias/EmailValidator) |
| AI-Platform-Kette | `0.14.1` (ai-platform) / Symfony `8.1.x` | Serializer, PropertyInfo/-Access, TypeInfo, Uid, String, EventDispatcher, enum-helper, phpDocumentor, phpdoc-parser, webmozart/assert, doctrine/deprecations | jeweils `CMS/assets/<paket>/` | `CMS_ASSETS/ai-0.14.1.zip` (`src/platform/`, ohne `src/Bridge/`, `src/Test/`, `dev/`), `serializer-8.1.8`, `property-info-8.1.8`, `type-info-8.1.8`, `uid-8.1.8`, `string-8.1.7`, `event-dispatcher-8.1.5`, PropertyAccess aus `symfony-8.1.8.zip` | ohne Tests/Command/DataCollector/DI | [ai.symfony.com](https://ai.symfony.com/) | [symfony/ai-platform](https://github.com/symfony/ai-platform) |
| `polyfill-*` | `1.43.0` | mbstring, ctype, intl-idn/-normalizer/-grapheme, uuid | `CMS/assets/polyfill-*/` | `CMS_ASSETS/polyfill-1.43.0.zip` (`src/Ctype`, `src/Intl/Idn`, `src/Intl/Grapheme`, `src/Uuid`), `polyfill-mbstring-1.43.0.zip`, `polyfill-intl-normalizer-1.43.0.zip` | `bootstrap.php` über `files`-Liste im Autoloader | [symfony.com/components/Polyfill Mbstring](https://symfony.com/components/Polyfill%20Mbstring) | [symfony/polyfill](https://github.com/symfony/polyfill) |
| `symfony-contracts` | `3.6.1` | Service-/Translation-/EventDispatcher-/HttpClient-/Deprecation-Contracts | `CMS/assets/symfony-contracts/` | `ASSETS/contracts-3.6.1/` | ersetzt den früheren Minimal-Shim; `Cache/` nicht übernommen (kein psr/cache) | [symfony.com](https://symfony.com/) | [symfony/contracts](https://github.com/symfony/contracts) |
| `tntsearch` | `5.3.0` | Volltextsuche | `CMS/assets/tntsearchsrc/`, `CMS/assets/tntsearchhelper/` | `CMS_ASSETS/tntsearch-5.3.0.zip` | `src/` und `helper/helpers.php` getrennt gespiegelt | [packagist.org/packages/teamtnt/tntsearch](https://packagist.org/packages/teamtnt/tntsearch) | [teamtnt/tntsearch](https://github.com/teamtnt/tntsearch) |
| `php-jwt` | `7.2.1` | JWT | `CMS/assets/php-jwt/` | `CMS_ASSETS/php-jwt-7.2.1.zip` (`src/`) | produktiv lokal gebündelt | [packagist.org/packages/firebase/php-jwt](https://packagist.org/packages/firebase/php-jwt) | [googleapis/php-jwt](https://github.com/googleapis/php-jwt) |
| `twofactorauth` | gebündelter Snapshot | TOTP / 2FA | `CMS/assets/twofactorauth/` | `ASSETS/twofactorauth/` | sicherheitskritisch, nur kapseln | [robthree.github.io/TwoFactorAuth](https://robthree.github.io/TwoFactorAuth/) | [RobThree/TwoFactorAuth](https://github.com/RobThree/TwoFactorAuth) |
| `webauthn` | gebündelter Snapshot | Passkeys / WebAuthn | `CMS/assets/webauthn/` | `ASSETS/webauthn/` | sicherheitskritisch, nur kapseln | [packagist.org/packages/lbuchs/webauthn](https://packagist.org/packages/lbuchs/webauthn) | [lbuchs/WebAuthn](https://github.com/lbuchs/WebAuthn) |
| `htmlpurifier` | gebündelter Snapshot | XSS-Schutz | `CMS/assets/htmlpurifier/` | `ASSETS/htmlpurifier/` | produktive Sanitizer-Basis | [htmlpurifier.org](https://htmlpurifier.org/) | [ezyang/htmlpurifier](https://github.com/ezyang/htmlpurifier) |
| `melbahja-seo` | gebündelter Snapshot | SEO-Helfer | `CMS/assets/melbahja-seo/` | `ASSETS/melbahja-seo/` | mittelfristig in Core-Services zerlegbar | [packagist.org/packages/melbahja/seo](https://packagist.org/packages/melbahja/seo) | [melbahja/seo](https://github.com/melbahja/seo) |
| `psr` | Log 3.0.2, EventDispatcher 1.0.0, Container 2.0.2, Clock 1.0.0 | PSR-Interfaces | `CMS/assets/psr/` | Packagist `psr/*` | vollständige Originalpakete statt Minimal-Shim | [php-fig.org/psr](https://www.php-fig.org/psr/) | [php-fig](https://github.com/php-fig) |
| `dompdf` | `3.1.6` | PDF-Erzeugung | `CMS/vendor/dompdf/` | `CMS_ASSETS/dompdf-3.1.6.zip` (`dompdf/vendor/`), dazu `PHP-CSS-Parser-9.5.0.zip` und `html5-php-2.11.0.zip` | **kein** `CMS/assets`-Bundle, sondern Vendor-Sonderfall; `sabberworm/php-css-parser` 9.5.0 und `masterminds/html5` 2.11.0 ersetzen die im Dompdf-Archiv enthaltenen 8.9.0/2.10.0, `composer/`-Autoload (inkl. `autoload_files.php`) dafür neu erzeugt | [dompdf.github.io](https://dompdf.github.io/) | [dompdf/dompdf](https://github.com/dompdf/dompdf) |
| `css/js/images` | intern | 365CMS-eigene Runtime-Assets | `CMS/assets/css/`, `CMS/assets/js/`, `CMS/assets/images/` | `ASSETS/css/`, `ASSETS/js/`, `ASSETS/images/` | kein Third-Party-Bundle; Bildinventar siehe [`DOC/assets/images/README.md`](images/README.md) | – | – |

Zusätzlich produktiv relevant:

- `CMS/assets/autoload.php` als zentraler PHP-Asset-Autoloader
- `CMS/core/VendorRegistry.php` als Diagnose-/Ladevertrag für gebündelte Libraries
- `CMS/core/Services/PdfService.php` als dokumentierter Dompdf-Einstieg

Die Website-/GitHub-Spalten nennen das Hauptprojekt. Die vollständige Upstream-Liste je Paket (inklusive aller Editor.js-Plugins, AI-Platform-Abhängigkeiten, PSR-Pakete und Dompdf-Unterpakete, Stand 2026-10-04) steht in [README.md → Upstream-Quellen](README.md#upstream-quellen-website--github).

---

## Synchronisationsregeln <!-- UPDATED: 2026-10-04 -->

Die Synchronisation von `/ASSETS` nach `/CMS/assets` ist **selektiv**, nicht spiegelnd. Ein vollständiges Rekursiv-Kopieren ganzer Upstream-Repositories würde Tests, Build-Tooling, `node_modules`, Dokumentation oder falsche Laufzeitpfade mit in die Runtime tragen.

Wichtige Regeln im aktuellen Stand:

1. **`CMS_ASSETS/` (früher `ASSETS/`) ist Staging, `CMS/assets/` ist Runtime.**
2. **Frontend-Bundles nur als Build-Artefakte übernehmen.** Das gilt insbesondere für `tabler`, `PhotoSwipe`, `editor.js` und `suneditor`.
3. **`editorjs` bleibt kuratiert.** Der Core `editorjs.umd.js` wird bytegleich aus dem offiziellen Build übernommen (aktuell 2.31.7); die lokal angepassten Plugin-Bundles (z. B. `hyperlink.umd.js`, `delimiter.umd.js`) sind davon unabhängig. Die Runtime orientiert sich an `CMS/core/Services/EditorJs/EditorJsAssetService.php`, nicht am gesamten Plugin-Baum; Zusatzartefakte wie `delimiter.umd.js` müssen bei neuen Plugin-Ständen gezielt gebaut oder aktualisiert werden.
4. **`suneditor` ist ein Sonderfall.** Für die Runtime werden `suneditor.min.js`, `suneditor.min.css` und `src/langs/de.js` (das ungenutzte `suneditor-contents.min.css` ist seit 3.5.0 nicht mehr Teil der Runtime) aus dem gebauten Paketstand übernommen; fehlt `dist/` nach einem frischen Upstream-Download, kommen die Artefakte aus dem npm-Paket gleicher Version oder SunEditor wird lokal gebaut. Die CMS-Aufrufer (`EditorService`, `js/admin-hub-site-edit.js`, Plugin `cms-jobprofile-generator`) nutzen die 3.x-API: Plugins explizit übergeben, `value`, `events.onChange`, `$.html.get()`/`$.html.set()`.
6. **`dompdf` bleibt außerhalb von `CMS/assets/`.** Der produktive Pfad ist `CMS/vendor/dompdf/`, geladen über `CMS/vendor/dompdf/autoload.php`.
7. **Sicherheits- und Standard-Bibliotheken nicht umstrukturieren, solange der Autoload-Vertrag stabil bleiben muss.** Das betrifft u. a. `htmlpurifier`, `php-jwt`, `webauthn`, `twofactorauth`, `ldaprecord`, `mailer`, `mime` und `translation`.

---

## CSS- und JavaScript-Architektur <!-- UPDATED: 2026-09-06 -->

### CSS

- **Basis:** `Tabler` plus 365CMS-spezifische Overrides in `CMS/assets/css/`
- **Produktive Styles:** `admin.css`, `admin-tabler.css`, `admin-hub-*`, `hub-sites.css`, `main.css`, `member-dashboard.css`, `cms-cookie-consent.css`
- **Editor-Sonderfälle:** `SunEditor` bringt eigenes CSS mit; `Editor.js` nutzt eigene Tool-/Block-Assets
- **Theme-Trennung:** Themes liefern zusätzliches Frontend-CSS in `CMS/themes/<theme>/`; globale Runtime-Assets bleiben davon getrennt

### JavaScript

- **Zentrale Runtime-Zone:** `CMS/assets/js/`
- **Wichtige produktive Dateien:** `admin.js`, `admin-content-editor.js`, `admin-media-integrations.js`, `admin-seo-redirects.js`, `admin-system-cron.js`, `member-dashboard.js`, `photoswipe-init.js`, `cookieconsent-init.js`
- **Editor-Zonen:**
  - `Editor.js` = kuratierter Block-Editor mit mehreren Plugin-Builds
  - `SunEditor` = Legacy-WYSIWYG für HTML-Eingabe
- **Ladeverträge:** Admin-/Frontend-Templates referenzieren Assets weiterhin manuell über `ASSETS_URL`, `SITE_URL . '/assets'`, `cms_asset_url()` oder `filemtime()`-basierte Varianten

---

## Build- und Update-Workflow <!-- UPDATED: 2026-09-06 -->

Es gibt **keine zentrale Repo-weite Bundling-Pipeline**. Asset-Updates sind weiterhin ein dokumentierter manueller bzw. teilmanueller Pfad.

Empfohlener Ablauf pro Update:

1. Quellstand in `CMS_ASSETS/` (ZIP-Archive) bzw. im Upstream-Paket prüfen
2. Nur den tatsächlich produktiven Runtime-Scope nach `CMS/assets/` bzw. `CMS/vendor/` übernehmen
3. `CMS/assets/autoload.php` sowie Core-Verträge (`VendorRegistry`, `PdfService`, Editor-Services) gegen Pfadänderungen prüfen
4. Falls ein Upstream nur Source-Dateien enthält, den Build lokal erzeugen und **erst dann** die Artefakte übernehmen
5. Doku synchron halten:
   - `DOC/ASSET.md`
   - `DOC/assets/README.md`
   - `DOC/ASSETS_OwnAssets.md`
   - `DOC/ASSETS_NEW.md` für neue Kandidaten außerhalb der Runtime

---

## Interne Bild-Assets <!-- UPDATED: 2026-09-06 -->

`CMS/assets/images/` ist der produktive interne Bildbestand der Runtime. Der
aktuelle Bestand umfasst 13 PNG-Dateien:

- zwei Dashboard-Icons für Admin und Member
- fünf Logos mit Text
- drei reine Schriftzüge
- drei Markenzeichen ohne Schriftzug

Die verbindliche Einzelauflistung mit Abmessungen und Verwendungszweck steht in
[`DOC/assets/images/README.md`](images/README.md). Neue Bilddateien sind
dort und in [`DOC/FILELIST.md`](../FILELIST.md) gemeinsam zu ergänzen.

---

## Neue Kandidaten außerhalb der Runtime <!-- UPDATED: 2026-10-04 -->

Folgende Pakete liegen als Archiv unter `CMS_ASSETS/`, sind aber **nicht** produktiv in `CMS/assets/` bzw. `CMS/vendor/` integriert:

- `symfony/cache` (`CMS_ASSETS/cache-8.1.8.zip`)
- `phpstan/phpstan` (`CMS_ASSETS/phpstan-2.2.16.zip`): Entwicklungswerkzeug, nie Teil der Runtime; produktiv bleibt nur `phpstan/phpdoc-parser` als AI-Platform-Abhängigkeit

Hinweis seit `3.3.42`: Tabler Icons wurden aus dem Beobachtungskandidaten-Status in die produktive Runtime übernommen. Die lokale Kopie unter `CMS/assets/tabler-icons/` ersetzt externe jsDelivr-/Tabler-Icon-Webfont-Requests im Admin-Header.

Für diese Kandidaten gilt:

- **nicht blind in die Runtime kopieren**
- zunächst Service-/Adapter-Schnittstellen im Core definieren
- transitive Abhängigkeiten vollständig bewerten
- Betriebsrisiken, Provider-Abhängigkeiten und Secrets-/Rate-Limits vor Integration dokumentieren

Ausnahme im aktuellen Stand:

- `symfony/ai-platform` zählt nicht mehr als reiner Außenkandidat, weil die Basis jetzt produktiv unter `CMS/assets/ai-platform/` liegt, vom zentralen Assets-Autoloader aufgelöst wird und in `Diagnose -> Assets` als Produktivpaket erscheint.
- Das ändert **nicht** den Architekturgrundsatz: weitere Provider-Bridges und Zusatzabhängigkeiten dürfen weiterhin nur kontrolliert über Core-Adapter und Feature-Gates in die Runtime wachsen.

Zusätzlich wichtig im aktuellen Stand:

- Das zuvor mitgeführte Paket `stichoza/google-translate-php` wurde **bewusst aus `/ASSETS` entfernt** und wird nicht weiter als aktiver Integrationskandidat geführt.
- Die früheren Beobachtungskandidaten `guzzlehttp/guzzle`, `adhocore/jwt`, `monolog-bundle` und `msgraph-sdk-php` lagen nur im alten Staging-Ordner `ASSETS/`, der nicht mehr im Repository ist. Sie werden nicht weiter geführt; der Graph-Mailversand nutzt eigene HTTP-Aufrufe (`AzureMailTokenProvider`). Die zugehörigen Staging-/Referenz-Einträge in `CMS/core/VendorRegistry.php` sind seit 3.5.0 entfernt.
- Für Übersetzungs- und Rewrite-Funktionen ist stattdessen ein **providerbasierter AI-Services-Ansatz** vorgesehen; Details siehe [ASSETS_NEW.md](ASSETS_NEW.md), [ai/AI-SERVICES.md](../ai/AI-SERVICES.md) und den Admin-Kontext unter [admin/system-settings/AI-SERVICES.md](../admin/system-settings/AI-SERVICES.md).

Die ausführliche Bewertungs- und Integrationsdoku dazu liegt in [ASSETS_NEW.md](ASSETS_NEW.md).

---

## Audit-Notiz zur Runtime-Integration <!-- UPDATED: 2026-10-04 -->

- Die produktive PHP-Dependency-Ladung erfolgt überwiegend über `CMS/assets/autoload.php`.
- Die dokumentierte Ausnahme bleibt `CMS/vendor/dompdf/autoload.php`.
- Die produktiv eingebundenen Symfony-8.1-Bundles (`mailer`, `mime`, `translation`, `yaml`, `clock` u. a.) deklarieren `PHP >= 8.4.1`; diese Mindestplattform ist Teil des offiziellen Runtime-Vertrags. Seit `3.5.0` liefert der Code sie als `CMS\Version::MIN_PHP` mit; `Version::minimumPhp()` nimmt das Maximum aus diesem Wert und `CMS_MIN_PHP_VERSION` aus `config.php`, damit ältere Installationen (deren `config.php` Core-Updates nicht ersetzen) nicht an der Plattformprüfung scheitern.
- Besonders update-sensibel bleiben Editor- und UI-Bundles mit Build-Artefakten (`editorjs`, `suneditor`, `tabler`, `photoswipe`).
- Für künftige Pflege wäre eine kleine zentrale Asset-/Versionierungs-Registry sinnvoll, damit Pfadlogik, Existenzprüfung und Cache-Busting nicht über viele Dateien verstreut bleiben.
