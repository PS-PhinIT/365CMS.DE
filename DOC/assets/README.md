# 365CMS Asset-Dokumentation
> **Stand:** 2026-10-04 | **Version:** 3.5.0 | **Status:** Aktuell | **Update:** 2026-10-04

## Inhaltsverzeichnis
- <a>Tabellarische Übersicht</a>
- <a>Upstream-Quellen (Website & GitHub)</a>
- <a>Runtime-Details</a>
- <a>Pfad, Autoload und Sonderfälle</a>
- <a>Interne Bild-Assets</a>
- <a>Neue Kandidaten außerhalb der Runtime</a>

---

## Tabellarische Übersicht <!-- UPDATED: 2026-10-04 -->
| Kategorie | Library | Runtime-Stand | Zweck | Eingebunden in |
|---|---|---|---|---|
| UI | `tabler` | `1.6.1` (nur `tabler.min.css` / `tabler.min.js`) | Admin-UI-Framework | Admin / Member |
| UI | `tabler-icons` | `3.48.0` (nur `tabler-icons.min.css` + woff2/woff/ttf) | Icon-Webfont | Admin |
| UI | `photoswipe` | `5.x`-Build | Lightbox | Frontend |
| Editor | `editorjs` | `2.31.7` (Core) | Block-Editor | Admin / Frontend |
| Editor | `suneditor` | `3.3.3` | Legacy-WYSIWYG | Admin |
| Auth | `php-jwt` | `7.2.1` | JWT | API / Auth |
| Auth | `ldaprecord` | `4.0.8` | LDAP / AD | Auth |
| Auth | `twofactorauth` | gebündelter Snapshot | TOTP | Auth |
| Auth | `bacon-qr-code` + `dasprid-enum` | `3.1.1` / `1.0.7` | lokale TOTP-QR-Codes als SVG (kein externer QR-Dienst, kein GD) | Auth / Member |
| Auth | `webauthn` | gebündelter Snapshot | Passkeys | Auth |
| Mail | `mailer` | `8.1.7` | Mail-Versand | System |
| Mail | `mime` | `8.1.7` | MIME-Objekte / Anhänge | System |
| Mail | `egulias-email-validator` + `doctrine-lexer` | `4.0.4` / `3.0.3` | RFC-Adressvalidierung (Pflicht für `Mime\Address` / SMTP) | System |
| Search | `tntsearch` | `5.3.0` | Volltextsuche | Suche |
| SEO | `melbahja-seo` | gebündelter Snapshot | SEO-Helfer | SEO |
| Security | `htmlpurifier` | gebündelter Snapshot | XSS-Schutz | System |
| Security | `dompurify` + `js/cms-csp-runtime.js` | `3.5.0` | Trusted-Types-`default`-Policy, Style-Nonce für dynamische `<style>` | Admin / Member / Frontend |
| i18n | `translation` | `8.1.5` | Übersetzungen | System |
| i18n | `yaml` | `8.1.8` | Parser für `CMS/lang/*.yaml` | System |
| AI | `symfony/ai-platform` | `0.14.1` (nur Basis, ohne `Bridge/`) | AI-Plattform-Grundvertrag / Core-Adapter-Basis | System / AI Services |
| AI (transitiv) | `serializer`, `property-info`, `property-access`, `type-info`, `uid`, `string`, `event-dispatcher` | `8.1.x` (Stand Symfony 8.1.8) | Abhängigkeiten der AI Platform | Transitiv |
| AI (transitiv) | `oskarstark-enum-helper`, `phpdocumentor-*`, `phpstan-phpdoc-parser`, `webmozart-assert`, `doctrine-deprecations` | aktuelle Stable | Abhängigkeiten der AI Platform | Transitiv |
| Util | `Carbon` | `3.14.2` (`src/Carbon` + `lazy/Carbon`) | Datum / Zeit | System |
| Util | `clock` + `psr/Clock` | `8.1.0` / `1.0.0` | Clock-Abstraktion für Carbon / AI | Transitiv |
| Util | `psr` | `Log` 3.0.2, `EventDispatcher` 1.0.0, `Container` 2.0.2, `Clock` 1.0.0 | PSR-Interfaces | Transitiv |
| Util | `symfony-contracts` | `3.6.1` | Service-/Translation-/EventDispatcher-/HttpClient-/Deprecation-Contracts | Transitiv |
| Util | `polyfill-*` | `1.43.0` | mbstring, ctype, intl-idn, intl-normalizer, intl-grapheme, uuid (nur ohne Extension aktiv) | Transitiv |
| PDF | `dompdf` | `3.1.6` (mit `php-css-parser` 9.5.0, `html5` 2.11.0) | PDF-Erzeugung | System |
| Intern | `css/js/images` | intern | 365CMS-eigene Runtime-Dateien | Admin / Frontend / Member |

---

## Upstream-Quellen (Website & GitHub) <!-- UPDATED: 2026-10-04 -->

Vollständige Liste aller **Fremd-Assets** unter `CMS/assets/` und `CMS/vendor/` mit offizieller Website und GitHub-Repository. Interne 365CMS-Dateien (`css/`, `js/`, `images/`, `autoload.php`) sind nicht enthalten.

Geprüft am **2026-10-04** gegen die Paket-Metadaten (`composer.json`, `vendor/dompdf/composer/installed.json`, JS-Lizenzheader) und die Registry-Einträge auf Packagist bzw. npm. Die Spalte *Upstream aktuell* nennt den neuesten Stable-Stand zum Prüfzeitpunkt; sie ist ein Hinweis für das nächste Update, kein Pflicht-Upgrade. Wo ein Projekt keine eigene Website hat, steht die Packagist- bzw. npm-Seite.

Hinweise zum Abgleich:

- `firebase/php-jwt` wird inzwischen unter **`googleapis/php-jwt`** gepflegt; der Paketname auf Packagist bleibt `firebase/php-jwt`.
- `nesbot/carbon` liegt inzwischen unter **`CarbonPHP/carbon`**, die Doku unter `carbonphp.github.io/carbon`.
- `symfony/ai-platform` ist ein Read-only-Split des Monorepos [symfony/ai](https://github.com/symfony/ai); Issues und PRs gehen dorthin.
- Die Symfony-Contracts werden aus dem Sammel-Repo `symfony/contracts` übernommen (Unterordner `Deprecation`, `EventDispatcher`, `HttpClient`, `Service`, `Translation`).
- `psr/simple-cache` wird nur von `ldaprecord` benötigt und fehlte bisher in den Listen.

### PHP- und JS-Bundles unter `CMS/assets/`

| Kategorie | Paket | Runtime-Pfad | Runtime-Stand | Upstream aktuell | Lizenz | Website | GitHub |
|---|---|---|---|---|---|---|---|
| UI | `@tabler/core` | `CMS/assets/tabler/` | 1.6.1 | 1.6.1 | MIT | [tabler.io](https://tabler.io/) | [tabler/tabler](https://github.com/tabler/tabler) |
| UI | `@tabler/icons-webfont` | `CMS/assets/tabler-icons/` | 3.48.0 | 3.48.0 | MIT | [tabler.io/icons](https://tabler.io/icons) | [tabler/tabler-icons](https://github.com/tabler/tabler-icons) |
| UI | `photoswipe` | `CMS/assets/photoswipe/` | 5.4.4 | 5.4.4 | MIT | [photoswipe.com](https://photoswipe.com/) | [dimsemenov/PhotoSwipe](https://github.com/dimsemenov/PhotoSwipe) |
| Security | `dompurify` | `CMS/assets/dompurify/` | 3.5.0 | 3.5.0 | Apache-2.0 / MPL-2.0 | [cure53.de/purify](https://cure53.de/purify) | [cure53/DOMPurify](https://github.com/cure53/DOMPurify) |
| Editor | `suneditor` | `CMS/assets/suneditor/` | 3.3.3 | 3.3.3 | MIT | [suneditor.com](https://suneditor.com/) | [JiHong88/suneditor](https://github.com/JiHong88/suneditor) |
| Auth | `firebase/php-jwt` | `CMS/assets/php-jwt/` | 7.2.1 | 7.2.1 | BSD-3-Clause | [packagist.org/packages/firebase/php-jwt](https://packagist.org/packages/firebase/php-jwt) | [googleapis/php-jwt](https://github.com/googleapis/php-jwt) |
| Auth | `directorytree/ldaprecord` | `CMS/assets/ldaprecord/` | 4.0.8 | 4.0.8 | MIT | [ldaprecord.com](https://ldaprecord.com/) | [DirectoryTree/LdapRecord](https://github.com/DirectoryTree/LdapRecord) |
| Auth | `robthree/twofactorauth` | `CMS/assets/twofactorauth/` | Snapshot | 3.0.3 | MIT | [robthree.github.io/TwoFactorAuth](https://robthree.github.io/TwoFactorAuth/) | [RobThree/TwoFactorAuth](https://github.com/RobThree/TwoFactorAuth) |
| Auth | `bacon/bacon-qr-code` | `CMS/assets/bacon-qr-code/` | 3.1.1 | 3.1.1 | BSD-2-Clause | [packagist.org/packages/bacon/bacon-qr-code](https://packagist.org/packages/bacon/bacon-qr-code) | [Bacon/BaconQrCode](https://github.com/Bacon/BaconQrCode) |
| Auth | `dasprid/enum` | `CMS/assets/dasprid-enum/` | 1.0.7 | 1.0.7 | BSD-2-Clause | [packagist.org/packages/dasprid/enum](https://packagist.org/packages/dasprid/enum) | [DASPRiD/Enum](https://github.com/DASPRiD/Enum) |
| Auth | `lbuchs/webauthn` | `CMS/assets/webauthn/` | Snapshot | 2.2.0 | MIT | [packagist.org/packages/lbuchs/webauthn](https://packagist.org/packages/lbuchs/webauthn) | [lbuchs/WebAuthn](https://github.com/lbuchs/WebAuthn) |
| Mail | `symfony/mailer` | `CMS/assets/mailer/` | 8.1.7 | 8.1.7 | MIT | [symfony.com/components/Mailer](https://symfony.com/components/Mailer) | [symfony/mailer](https://github.com/symfony/mailer) |
| Mail | `symfony/mime` | `CMS/assets/mime/` | 8.1.7 | 8.1.7 | MIT | [symfony.com/components/Mime](https://symfony.com/components/Mime) | [symfony/mime](https://github.com/symfony/mime) |
| Mail | `egulias/email-validator` | `CMS/assets/egulias-email-validator/` | 4.0.4 | 4.0.4 | MIT | [packagist.org/packages/egulias/email-validator](https://packagist.org/packages/egulias/email-validator) | [egulias/EmailValidator](https://github.com/egulias/EmailValidator) |
| Mail | `doctrine/lexer` | `CMS/assets/doctrine-lexer/` | 3.0.3 | 3.0.3 | MIT | [doctrine-project.org/projects/lexer.html](https://www.doctrine-project.org/projects/lexer.html) | [doctrine/lexer](https://github.com/doctrine/lexer) |
| Search | `teamtnt/tntsearch` | `CMS/assets/tntsearchsrc/`, `CMS/assets/tntsearchhelper/` | 5.3.0 | 5.3.0 | MIT | [packagist.org/packages/teamtnt/tntsearch](https://packagist.org/packages/teamtnt/tntsearch) | [teamtnt/tntsearch](https://github.com/teamtnt/tntsearch) |
| SEO | `melbahja/seo` | `CMS/assets/melbahja-seo/` | Snapshot | 3.0.6 | MIT | [packagist.org/packages/melbahja/seo](https://packagist.org/packages/melbahja/seo) | [melbahja/seo](https://github.com/melbahja/seo) |
| Security | `ezyang/htmlpurifier` | `CMS/assets/htmlpurifier/` | 4.19.0 | 4.19.1 | LGPL-2.1-or-later | [htmlpurifier.org](https://htmlpurifier.org/) | [ezyang/htmlpurifier](https://github.com/ezyang/htmlpurifier) |
| Cron | `poliander/cron` | `CMS/assets/cron/` | 3.3.1 | 3.3.1 | GPL-3.0-or-later | [packagist.org/packages/poliander/cron](https://packagist.org/packages/poliander/cron) | [poliander/cron](https://github.com/poliander/cron) |
| i18n | `symfony/translation` | `CMS/assets/translation/` | 8.1.5 | 8.1.5 | MIT | [symfony.com/components/Translation](https://symfony.com/components/Translation) | [symfony/translation](https://github.com/symfony/translation) |
| i18n | `symfony/yaml` | `CMS/assets/yaml/` | 8.1.8 | 8.1.8 | MIT | [symfony.com/components/Yaml](https://symfony.com/components/Yaml) | [symfony/yaml](https://github.com/symfony/yaml) |
| AI | `symfony/ai-platform` | `CMS/assets/ai-platform/` | 0.14.1 | 0.14.1 | MIT | [ai.symfony.com](https://ai.symfony.com/) | [symfony/ai-platform](https://github.com/symfony/ai-platform) |
| AI (transitiv) | `symfony/serializer` | `CMS/assets/serializer/` | 8.1.8 | 8.1.8 | MIT | [symfony.com/components/Serializer](https://symfony.com/components/Serializer) | [symfony/serializer](https://github.com/symfony/serializer) |
| AI (transitiv) | `symfony/property-info` | `CMS/assets/property-info/` | 8.1.8 | 8.1.8 | MIT | [symfony.com/components/PropertyInfo](https://symfony.com/components/PropertyInfo) | [symfony/property-info](https://github.com/symfony/property-info) |
| AI (transitiv) | `symfony/property-access` | `CMS/assets/property-access/` | 8.1.4 | 8.1.4 | MIT | [symfony.com/components/PropertyAccess](https://symfony.com/components/PropertyAccess) | [symfony/property-access](https://github.com/symfony/property-access) |
| AI (transitiv) | `symfony/type-info` | `CMS/assets/type-info/` | 8.1.8 | 8.1.8 | MIT | [symfony.com/components/TypeInfo](https://symfony.com/components/TypeInfo) | [symfony/type-info](https://github.com/symfony/type-info) |
| AI (transitiv) | `symfony/uid` | `CMS/assets/uid/` | 8.1.8 | 8.1.8 | MIT | [symfony.com/components/Uid](https://symfony.com/components/Uid) | [symfony/uid](https://github.com/symfony/uid) |
| AI (transitiv) | `symfony/string` | `CMS/assets/string/` | 8.1.7 | 8.1.7 | MIT | [symfony.com/components/String](https://symfony.com/components/String) | [symfony/string](https://github.com/symfony/string) |
| AI (transitiv) | `symfony/event-dispatcher` | `CMS/assets/event-dispatcher/` | 8.1.5 | 8.1.5 | MIT | [symfony.com/components/EventDispatcher](https://symfony.com/components/EventDispatcher) | [symfony/event-dispatcher](https://github.com/symfony/event-dispatcher) |
| AI (transitiv) | `oskarstark/enum-helper` | `CMS/assets/oskarstark-enum-helper/` | aktuelle Stable | 1.8.4 | MIT | [packagist.org/packages/oskarstark/enum-helper](https://packagist.org/packages/oskarstark/enum-helper) | [OskarStark/enum-helper](https://github.com/OskarStark/enum-helper) |
| AI (transitiv) | `phpdocumentor/reflection-common` | `CMS/assets/phpdocumentor-reflection-common/` | aktuelle Stable | 2.2.1 | MIT | [phpdoc.org](https://www.phpdoc.org/) | [phpDocumentor/ReflectionCommon](https://github.com/phpDocumentor/ReflectionCommon) |
| AI (transitiv) | `phpdocumentor/reflection-docblock` | `CMS/assets/phpdocumentor-reflection-docblock/` | aktuelle Stable | 6.0.3 | MIT | [phpdoc.org](https://www.phpdoc.org/) | [phpDocumentor/ReflectionDocBlock](https://github.com/phpDocumentor/ReflectionDocBlock) |
| AI (transitiv) | `phpdocumentor/type-resolver` | `CMS/assets/phpdocumentor-type-resolver/` | aktuelle Stable | 2.1.0 | MIT | [phpdoc.org](https://www.phpdoc.org/) | [phpDocumentor/TypeResolver](https://github.com/phpDocumentor/TypeResolver) |
| AI (transitiv) | `phpstan/phpdoc-parser` | `CMS/assets/phpstan-phpdoc-parser/` | aktuelle Stable | 2.3.6 | MIT | [phpstan.org](https://phpstan.org/) | [phpstan/phpdoc-parser](https://github.com/phpstan/phpdoc-parser) |
| AI (transitiv) | `webmozart/assert` | `CMS/assets/webmozart-assert/` | aktuelle Stable | 2.4.1 | MIT | [packagist.org/packages/webmozart/assert](https://packagist.org/packages/webmozart/assert) | [webmozarts/assert](https://github.com/webmozarts/assert) |
| AI (transitiv) | `doctrine/deprecations` | `CMS/assets/doctrine-deprecations/` | aktuelle Stable | 1.1.6 | MIT | [doctrine-project.org](https://www.doctrine-project.org/) | [doctrine/deprecations](https://github.com/doctrine/deprecations) |
| Util | `nesbot/carbon` | `CMS/assets/Carbon/` | 3.14.2 | 3.14.2 | MIT | [carbonphp.github.io/carbon](https://carbonphp.github.io/carbon/) | [CarbonPHP/carbon](https://github.com/CarbonPHP/carbon) |
| Util | `symfony/clock` | `CMS/assets/clock/` | 8.1.0 | 8.1.0 | MIT | [symfony.com/components/Clock](https://symfony.com/components/Clock) | [symfony/clock](https://github.com/symfony/clock) |
| Util | `symfony/contracts (service, translation, event-dispatcher, http-client, deprecation)` | `CMS/assets/symfony-contracts/` | 3.6.1 | 3.7.3 | MIT | [symfony.com](https://symfony.com/) | [symfony/contracts](https://github.com/symfony/contracts) |
| Util | `symfony/polyfill-mbstring` | `CMS/assets/polyfill-mbstring/` | 1.43.0 | 1.43.0 | MIT | [symfony.com/components/Polyfill Mbstring](https://symfony.com/components/Polyfill%20Mbstring) | [symfony/polyfill-mbstring](https://github.com/symfony/polyfill-mbstring) |
| Util | `symfony/polyfill-ctype` | `CMS/assets/polyfill-ctype/` | 1.43.0 | 1.37.0 | MIT | [symfony.com/components/Polyfill Ctype](https://symfony.com/components/Polyfill%20Ctype) | [symfony/polyfill-ctype](https://github.com/symfony/polyfill-ctype) |
| Util | `symfony/polyfill-intl-idn` | `CMS/assets/polyfill-intl-idn/` | 1.43.0 | 1.43.0 | MIT | [symfony.com/components/Polyfill Intl Idn](https://symfony.com/components/Polyfill%20Intl%20Idn) | [symfony/polyfill-intl-idn](https://github.com/symfony/polyfill-intl-idn) |
| Util | `symfony/polyfill-intl-normalizer` | `CMS/assets/polyfill-intl-normalizer/` | 1.43.0 | 1.43.0 | MIT | [symfony.com/components/Polyfill Intl Normalizer](https://symfony.com/components/Polyfill%20Intl%20Normalizer) | [symfony/polyfill-intl-normalizer](https://github.com/symfony/polyfill-intl-normalizer) |
| Util | `symfony/polyfill-intl-grapheme` | `CMS/assets/polyfill-intl-grapheme/` | 1.43.0 | 1.43.0 | MIT | [symfony.com/components/Polyfill Intl Grapheme](https://symfony.com/components/Polyfill%20Intl%20Grapheme) | [symfony/polyfill-intl-grapheme](https://github.com/symfony/polyfill-intl-grapheme) |
| Util | `symfony/polyfill-uuid` | `CMS/assets/polyfill-uuid/` | 1.43.0 | 1.43.0 | MIT | [symfony.com/components/Polyfill UUID](https://symfony.com/components/Polyfill%20UUID) | [symfony/polyfill-uuid](https://github.com/symfony/polyfill-uuid) |
| PSR | `psr/log` | `CMS/assets/psr/Log/` | 3.0.2 | 3.0.2 | MIT | [php-fig.org/psr/psr-3](https://www.php-fig.org/psr/psr-3/) | [php-fig/log](https://github.com/php-fig/log) |
| PSR | `psr/container` | `CMS/assets/psr/Container/` | 2.0.2 | 2.0.2 | MIT | [php-fig.org/psr/psr-11](https://www.php-fig.org/psr/psr-11/) | [php-fig/container](https://github.com/php-fig/container) |
| PSR | `psr/event-dispatcher` | `CMS/assets/psr/EventDispatcher/` | 1.0.0 | 1.0.0 | MIT | [php-fig.org/psr/psr-14](https://www.php-fig.org/psr/psr-14/) | [php-fig/event-dispatcher](https://github.com/php-fig/event-dispatcher) |
| PSR | `psr/clock` | `CMS/assets/psr/Clock/` | 1.0.0 | 1.0.0 | MIT | [php-fig.org/psr/psr-20](https://www.php-fig.org/psr/psr-20/) | [php-fig/clock](https://github.com/php-fig/clock) |
| PSR | `psr/simple-cache` | `CMS/assets/psr/SimpleCache/` | 3.x | 3.0.0 | MIT | [php-fig.org/psr/psr-16](https://www.php-fig.org/psr/psr-16/) | [php-fig/simple-cache](https://github.com/php-fig/simple-cache) |

### Editor.js (Core, Tools, Tunes, Plugins) unter `CMS/assets/editorjs/`

| Kategorie | Paket | Runtime-Pfad | Runtime-Stand | Upstream aktuell | Lizenz | Website | GitHub |
|---|---|---|---|---|---|---|---|
| Editor | `@editorjs/editorjs` | `CMS/assets/editorjs/editorjs.umd.js` | 2.31.7 | 2.31.7 | Apache-2.0 | [editorjs.io](https://editorjs.io/) | [codex-team/editor.js](https://github.com/codex-team/editor.js) |
| Editor.js-Tool | `@editorjs/paragraph` | `CMS/assets/editorjs/paragraph.umd.js` | Snapshot | 2.11.7 | MIT | [npmjs.com/package/@editorjs/paragraph](https://www.npmjs.com/package/@editorjs/paragraph) | [editor-js/paragraph](https://github.com/editor-js/paragraph) |
| Editor.js-Tool | `@editorjs/header` | `CMS/assets/editorjs/header.umd.js` | Snapshot | 2.8.9 | MIT | [npmjs.com/package/@editorjs/header](https://www.npmjs.com/package/@editorjs/header) | [editor-js/header](https://github.com/editor-js/header) |
| Editor.js-Tool | `@editorjs/list` | `CMS/assets/editorjs/editorjs-list.umd.js` | Snapshot | 2.0.9 | MIT | [npmjs.com/package/@editorjs/list](https://www.npmjs.com/package/@editorjs/list) | [editor-js/list](https://github.com/editor-js/list) |
| Editor.js-Tool | `@editorjs/image` | `CMS/assets/editorjs/image.umd.js` | Snapshot | 2.10.3 | MIT | [npmjs.com/package/@editorjs/image](https://www.npmjs.com/package/@editorjs/image) | [editor-js/image](https://github.com/editor-js/image) |
| Editor.js-Tool | `@editorjs/quote` | `CMS/assets/editorjs/quote.umd.js` | Snapshot | 2.7.6 | MIT | [npmjs.com/package/@editorjs/quote](https://www.npmjs.com/package/@editorjs/quote) | [editor-js/quote](https://github.com/editor-js/quote) |
| Editor.js-Tool | `@editorjs/code` | `CMS/assets/editorjs/code.umd.js` | Snapshot | 2.9.4 | MIT | [npmjs.com/package/@editorjs/code](https://www.npmjs.com/package/@editorjs/code) | [editor-js/code](https://github.com/editor-js/code) |
| Editor.js-Tool | `@editorjs/table` | `CMS/assets/editorjs/table.umd.js` | Snapshot | 2.4.6 | MIT | [npmjs.com/package/@editorjs/table](https://www.npmjs.com/package/@editorjs/table) | [editor-js/table](https://github.com/editor-js/table) |
| Editor.js-Tool | `@coolbytes/editorjs-delimiter` | `CMS/assets/editorjs/delimiter.umd.js` | 1.0.2 (lokal gebaut) | 1.0.4 | MIT | [npmjs.com/package/@coolbytes/editorjs-delimiter](https://www.npmjs.com/package/@coolbytes/editorjs-delimiter) | [CoolBytesIN/editorjs-delimiter](https://github.com/CoolBytesIN/editorjs-delimiter) |
| Editor.js-Tool | `@editorjs/embed` | `CMS/assets/editorjs/embed.umd.js` | Snapshot | 2.8.0 | MIT | [npmjs.com/package/@editorjs/embed](https://www.npmjs.com/package/@editorjs/embed) | [editor-js/embed](https://github.com/editor-js/embed) |
| Editor.js-Tool | `@editorjs/link` | `CMS/assets/editorjs/link.umd.js` | Snapshot | 2.6.2 | MIT | [npmjs.com/package/@editorjs/link](https://www.npmjs.com/package/@editorjs/link) | [editor-js/link](https://github.com/editor-js/link) |
| Editor.js-Tool | `@editorjs/attaches` | `CMS/assets/editorjs/attaches.umd.js` | Snapshot | 1.3.2 | MIT | [npmjs.com/package/@editorjs/attaches](https://www.npmjs.com/package/@editorjs/attaches) | [editor-js/attaches](https://github.com/editor-js/attaches) |
| Editor.js-Tool | `@editorjs/warning` | `CMS/assets/editorjs/warning.umd.js` | Snapshot | 1.4.1 | MIT | [npmjs.com/package/@editorjs/warning](https://www.npmjs.com/package/@editorjs/warning) | [editor-js/warning](https://github.com/editor-js/warning) |
| Editor.js-Tool | `editorjs-alert` | `CMS/assets/editorjs/alert.umd.js` | Snapshot | 1.1.4 | MIT | [npmjs.com/package/editorjs-alert](https://www.npmjs.com/package/editorjs-alert) | [vishaltelangre/editorjs-alert](https://github.com/vishaltelangre/editorjs-alert) |
| Editor.js-Tool | `@editorjs/raw` | `CMS/assets/editorjs/raw.umd.js` | Snapshot | 2.5.1 | MIT | [npmjs.com/package/@editorjs/raw](https://www.npmjs.com/package/@editorjs/raw) | [editor-js/raw](https://github.com/editor-js/raw) |
| Editor.js-Tool | `@editorjs/inline-code` | `CMS/assets/editorjs/inline-code.umd.js` | Snapshot | 1.5.2 | MIT | [npmjs.com/package/@editorjs/inline-code](https://www.npmjs.com/package/@editorjs/inline-code) | [editor-js/inline-code](https://github.com/editor-js/inline-code) |
| Editor.js-Tool | `@editorjs/underline` | `CMS/assets/editorjs/underline.umd.js` | Snapshot | 1.2.1 | MIT | [npmjs.com/package/@editorjs/underline](https://www.npmjs.com/package/@editorjs/underline) | [editor-js/underline](https://github.com/editor-js/underline) |
| Editor.js-Tool | `@sotaproject/strikethrough` | `CMS/assets/editorjs/strikethrough.umd.js` | Snapshot | 1.0.1 | MIT | [npmjs.com/package/@sotaproject/strikethrough](https://www.npmjs.com/package/@sotaproject/strikethrough) | [sotaproject/strikethrough](https://github.com/sotaproject/strikethrough) |
| Editor.js-Tool | `editorjs-hyperlink` | `CMS/assets/editorjs/hyperlink.umd.js` | Snapshot | 1.0.6 | MIT | [npmjs.com/package/editorjs-hyperlink](https://www.npmjs.com/package/editorjs-hyperlink) | [trinhtam/editorjs-hyperlink](https://github.com/trinhtam/editorjs-hyperlink) |
| Editor.js-Tool | `editorjs-text-color-plugin` | `CMS/assets/editorjs/text-color.umd.js` | Snapshot | 2.0.4 | MIT | [npmjs.com/package/editorjs-text-color-plugin](https://www.npmjs.com/package/editorjs-text-color-plugin) | [flaming-cl/editorjs-text-color-plugin](https://github.com/flaming-cl/editorjs-text-color-plugin) |
| Editor.js-Tool | `@iizotikov/editor-js-tg-spoiler` | `CMS/assets/editorjs/spoiler.umd.js` | Snapshot | 1.0.6 | MIT | [npmjs.com/package/@iizotikov/editor-js-tg-spoiler](https://www.npmjs.com/package/@iizotikov/editor-js-tg-spoiler) | [izotikov/editor-js-tg-spoiler](https://github.com/izotikov/editor-js-tg-spoiler) |
| Editor.js-Tune | `editorjs-anchor` | `CMS/assets/editorjs/anchor.umd.js` | Snapshot | 1.1.3 | MIT | [npmjs.com/package/editorjs-anchor](https://www.npmjs.com/package/editorjs-anchor) | [VolgaIgor/editorjs-anchor](https://github.com/VolgaIgor/editorjs-anchor) |
| Editor.js-Tune | `editorjs-text-alignment-blocktune` | `CMS/assets/editorjs/alignment-tune.umd.js` | Snapshot | 1.0.3 | MIT | [npmjs.com/package/editorjs-text-alignment-blocktune](https://www.npmjs.com/package/editorjs-text-alignment-blocktune) | [kaaaaaaaaaaai/editorjs-alignment-blocktune](https://github.com/kaaaaaaaaaaai/editorjs-alignment-blocktune) |
| Editor.js-Tune | `editorjs-indent-tune` | `CMS/assets/editorjs/indent-tune.umd.js` | Snapshot | 1.4.4 | MIT | [npmjs.com/package/editorjs-indent-tune](https://www.npmjs.com/package/editorjs-indent-tune) | [sebmeister2077/editorjs-indent-tune](https://github.com/sebmeister2077/editorjs-indent-tune) |
| Editor.js-Tune | `@editorjs/text-variant-tune` | `CMS/assets/editorjs/text-variant-tune.umd.js` | Snapshot | 1.0.3 | MIT | [npmjs.com/package/@editorjs/text-variant-tune](https://www.npmjs.com/package/@editorjs/text-variant-tune) | [editor-js/text-variant-tune](https://github.com/editor-js/text-variant-tune) |
| Editor.js-Tool | `editorjs-collapsible-block` | `CMS/assets/editorjs/accordion.umd.js` | Snapshot | 1.0.1 | MIT | [npmjs.com/package/editorjs-collapsible-block](https://www.npmjs.com/package/editorjs-collapsible-block) | [sebmeister2077/editorjs-accordion](https://github.com/sebmeister2077/editorjs-accordion) |
| Editor.js-Plugin | `editorjs-undo` | `CMS/assets/editorjs/undo.umd.js` | Snapshot | 2.0.28 | MIT | [npmjs.com/package/editorjs-undo](https://www.npmjs.com/package/editorjs-undo) | [kommitters/editorjs-undo](https://github.com/kommitters/editorjs-undo) |
| Editor.js-Plugin | `editorjs-drag-drop` | `CMS/assets/editorjs/drag-drop.umd.js` | Snapshot | 1.1.16 | MIT | [npmjs.com/package/editorjs-drag-drop](https://www.npmjs.com/package/editorjs-drag-drop) | [kommitters/editorjs-drag-drop](https://github.com/kommitters/editorjs-drag-drop) |

### Dompdf-Vendor-Bundle unter `CMS/vendor/dompdf/`

| Kategorie | Paket | Runtime-Pfad | Runtime-Stand | Upstream aktuell | Lizenz | Website | GitHub |
|---|---|---|---|---|---|---|---|
| PDF (Vendor) | `dompdf/dompdf` | `CMS/vendor/dompdf/dompdf/dompdf/` | 3.1.6 | 3.1.6 | LGPL-2.1 | [dompdf.github.io](https://dompdf.github.io/) | [dompdf/dompdf](https://github.com/dompdf/dompdf) |
| PDF (Vendor) | `dompdf/php-font-lib` | `CMS/vendor/dompdf/dompdf/php-font-lib/` | 1.0.2 | 1.0.2 | LGPL-2.1-or-later | [packagist.org/packages/dompdf/php-font-lib](https://packagist.org/packages/dompdf/php-font-lib) | [dompdf/php-font-lib](https://github.com/dompdf/php-font-lib) |
| PDF (Vendor) | `dompdf/php-svg-lib` | `CMS/vendor/dompdf/dompdf/php-svg-lib/` | 1.0.2 | 1.0.2 | LGPL-3.0-or-later | [packagist.org/packages/dompdf/php-svg-lib](https://packagist.org/packages/dompdf/php-svg-lib) | [dompdf/php-svg-lib](https://github.com/dompdf/php-svg-lib) |
| PDF (Vendor) | `masterminds/html5` | `CMS/vendor/dompdf/masterminds/html5/` | 2.11.0 | 2.11.0 | MIT | [masterminds.github.io/html5-php](https://masterminds.github.io/html5-php/) | [Masterminds/html5-php](https://github.com/Masterminds/html5-php) |
| PDF (Vendor) | `sabberworm/php-css-parser` | `CMS/vendor/dompdf/sabberworm/php-css-parser/` | 9.5.0 | 9.5.0 | MIT | [packagist.org/packages/sabberworm/php-css-parser](https://packagist.org/packages/sabberworm/php-css-parser) | [MyIntervals/PHP-CSS-Parser](https://github.com/MyIntervals/PHP-CSS-Parser) |

---

## Runtime-Details <!-- UPDATED: 2026-09-26 -->

Die produktive Detaildoku richtet sich nach den **Laufzeitpfaden in `CMS/assets/`** und dem dokumentierten Vendor-Sonderfall `CMS/vendor/dompdf/`.

Wichtig im aktuellen Stand:

- `CMS_ASSETS/` (früher `ASSETS/`) ist Quell- und Staging-Bereich, **nicht** der direkte Webroot-Assetpfad
- `editorjs` und `suneditor` sind keine simplen Ordnerkopien, sondern kuratierte bzw. gebaute Runtime-Sets
- `editorjs` benötigt für das produktive `delimiter.umd.js` bei neuen Plugin-Ständen einen gezielten Build-/Refresh-Pfad
- `tabler`, `tabler-icons` und `PhotoSwipe` werden nur mit ihren tatsächlich geladenen Dateien übernommen (keine RTL-/Map-/SVG-Font-/Varianten-Dateien)
- `tntsearch` liegt produktiv bewusst aufgeteilt in `tntsearchsrc/` und `tntsearchhelper/`
- `images/` enthält produktive Dashboard-, Logo- und Branding-Bestände
- `ai-platform/` enthält die produktiv registrierte Symfony-AI-Platform-Basis inklusive aller Pflichtabhängigkeiten; Provider-Bridges und `symfony/http-client` bleiben separat zu bewerten
- Bibliotheken werden ohne `Test*/`, `Command/`, `DataCollector/`, `DependencyInjection/`, Extractor- und Framework-Integrationsordner übernommen

---

## Interne Bild-Assets <!-- UPDATED: 2026-09-06 -->

Der produktive Bildbestand liegt unter `CMS/assets/images/` und umfasst aktuell
13 PNG-Dateien für Dashboard-Icons, Logos, Schriftzüge und Markenzeichen.

Die vollständige Dateiliste mit Abmessungen und Verwendungszweck steht in
[`assets/images/README.md`](images/README.md). Die übergeordnete Runtime-Struktur
ist zusätzlich in [`DOC/FILELIST.md`](../FILELIST.md) dokumentiert.

Für PHP-Referenzen gilt der zentrale Asset-Helper:

```php
cms_asset_url('images/LOGO_365CMS-75px.png')
```

---

## Pfad, Autoload und Sonderfälle <!-- UPDATED: 2026-09-26 -->

- **Runtime-Pfad:** `CMS/assets/`
- **Zentraler Autoloader:** `CMS/assets/autoload.php` – PSR-4-Map (`cms_vendor_psr4_map()`), Composer-`files`-Bootstraps (Polyfills, `trigger_deprecation()`, `Clock\now()`, `String\u()`) und Classmap für den `Normalizer`-Stub
- **Neue Library aufnehmen:** Paket ohne Tests/Tooling nach `CMS/assets/<name>/` kopieren, Präfix in `cms_vendor_psr4_map()` eintragen, ggf. `files` ergänzen und in `CMS/core/VendorRegistry.php` (Bundle + ggf. Funktions-Probe) registrieren
- **Vendor-Sonderfall:** `CMS/vendor/dompdf/` wird separat über `CMS/vendor/dompdf/autoload.php` geladen
- **SunEditor-Sonderfall:** Die Runtime wird aus `dist/` plus `src/langs/de.js` übernommen; fehlt `dist/` nach einem frischen Upstream-Download (wie beim GitHub-Archiv `suneditor-3.3.3.zip`), werden die Artefakte aus dem npm-Paket gleicher Version genommen oder lokal gebaut. Seit 3.x müssen Toolbar-Plugins beim `SUNEDITOR.create()` explizit übergeben werden (`plugins`), Inhalte laufen über `value`/`$.html.get()`/`$.html.set()` und Änderungen über `events.onChange`
- **Editor.js-Sonderfall:** Der Runtime-Vertrag folgt `CMS/core/Services/EditorJs/EditorJsAssetService.php`, nicht dem kompletten Plugin-Baum; `delimiter.umd.js` wurde für den aktuellen Stand gezielt aus `editorjs-delimiter-version1.0.2` neu gebaut
- **JS/CSS-Ladung:** erfolgt weiterhin manuell über Admin-Partials, Theme-Templates und Service-spezifische Loader; es gibt keine zentrale Bundling-Pipeline

Zusätzliche Hinweise:

- `cookieconsent`, `filepond`, `elfinder`, `simplepie` und `gridjs` sind keine Runtime-Bundles mehr und wurden in `3.5.0` aus `CMS/assets/` entfernt; ihre historischen READMEs unter `DOC/assets/` (inkl. `msgraph`) wurden in `3.5.0` gelöscht
- die produktiv eingebundenen Symfony-8.1-Bundles deklarieren `PHP >= 8.4.1`; der Code führt diese Untergrenze seit `3.5.0` als `CMS\Version::MIN_PHP` und wertet sie zusammen mit `CMS_MIN_PHP_VERSION` aus (`Version::minimumPhp()`)
- `.htaccess` sperrt `vendor/` komplett sowie PHP-/Metadaten-/versteckte Dateien unter `assets/`
- **CSP-Vertrag:** Inline-`<script>`/`<style>` nur mit `Security::instance()->nonceAttr()`; keine Inline-Event-Handler (`onclick` …) – stattdessen `data-cms-*`-Attribute aus `js/cms-inline-actions.js`; `style="`-Attribute bleiben per CSP blockiert (`style-src-attr`); `CMS\Http\InlineStyleRewriter` (`core/Http/InlineStyleRewriter.php`, gestartet in `Bootstrap::run()`) überführt sie in vollständigen HTML-Antworten serverseitig in Klassen plus einen `<style nonce>`-Block vor `</head>`. Unsichere Werte (`{ } < > @ \`, Kommentare) bleiben blockiert; Fragmente, Downloads, Antworten mit `Content-Length`, gestreamte oder > 4 MB große Ausgaben bleiben unverändert; per JavaScript eingefügte Style-Attribute werden nicht umgeschrieben (dort CSSOM `el.style.*` nutzen). Tests: `TESTS/csp-style-rewriter/run.php`
- `js/tabler-bootstrap-bridge.js` stellt `window.bootstrap` für Tabler ab 1.4 (`window.tabler`, in 1.6 weiterhin `window.tabler.bootstrap`) bereit
- seit `3.3.42` sind Tabler Icons produktiv lokal unter `CMS/assets/tabler-icons/` eingebunden; der Admin-Header lädt keine externen jsDelivr-/Tabler-Icon-Webfonts mehr
- `DOC/FILELIST.md` bleibt die lesbare Strukturreferenz für die aktuelle Runtime-Oberfläche

---

## Neue Kandidaten außerhalb der Runtime <!-- UPDATED: 2026-10-04 -->

Neu dokumentierte, aber noch nicht produktiv integrierte Pakete:

- `symfony/cache` als `CMS_ASSETS/cache-8.1.8.zip`
- `phpstan/phpstan` als `CMS_ASSETS/phpstan-2.2.16.zip` (Entwicklungswerkzeug für statische Analyse, kein Runtime-Paket; die AI-Platform nutzt nur `phpstan/phpdoc-parser`)

Diese Kandidaten sind im aktuellen Core **nicht aktiv verdrahtet**. Die Code- und Laufzeitprüfung zeigte hierfür keine produktiven Referenzen in `CMS/**`; deshalb wurden sie beim Refresh nach `3.4.00` bewusst nicht in die aktive Runtime übernommen.

Tabler Icons waren früher ebenfalls Beobachtungskandidat, sind seit `3.3.42` aber gezielt als lokales Runtime-Bundle nach `CMS/assets/tabler-icons/` übernommen und ersetzen dort den früheren CDN-Request.

Wichtig dazu: `symfony/ai-platform` gehört **nicht mehr** in diese Liste, weil die Basis jetzt bewusst produktiv unter `CMS/assets/ai-platform/` gespiegelt, über `CMS/assets/autoload.php` auflösbar gemacht und in `Diagnose -> Assets` registriert wurde.

Hinweis zum jüngsten Bereinigungsschritt:

- `stichoza/google-translate-php` wird **nicht mehr** als Staging-Kandidat mitgeführt und wurde aus `/ASSETS` entfernt.
- Für Übersetzungsfunktionen soll 365CMS stattdessen auf einen **AI-Services-Bereich mit Provider-Scope** setzen, nicht auf eine einzelne inoffizielle Crawling-Bibliothek.

Diese Kandidaten benötigen vor einer Aufnahme:

1. vollständige Dependency-Prüfung
2. Service-/Adapter-Schicht im Core
3. Security-/Rate-Limit-/Provider-Konzept
4. dokumentierte Entscheidung, ob lokales Bundling überhaupt sinnvoll ist

Die ausführliche Bewertungsdoku steht in [ASSETS_NEW.md](ASSETS_NEW.md). Die kanonische AI-Konzeption liegt zusätzlich in [../ai/AI-SERVICES.md](../ai/AI-SERVICES.md), ergänzt um den Admin-Kontext unter [../admin/system-settings/AI-SERVICES.md](../admin/system-settings/AI-SERVICES.md). Die Roadmap für Eigenersatz und Wrapper-Strategien steht in [ASSETS_OwnAssets.md](ASSETS_OwnAssets.md).

---

## Prüfstand 2026-10-04 (Asset-Update 3.5.0)

Abgleich mit `CMS/assets/`, `CMS/assets/autoload.php` und `CMS/core/VendorRegistry.php`:

| Library | Verifizierter Stand | Einbindung im Code |
|---|---|---|
| `dompurify` | 3.5.0 (`purify.min.js`) | `cms_csp_runtime_tags()` in `includes/functions/options-runtime.php` zusammen mit `js/cms-csp-runtime.js` |
| `editorjs` | 2.31.7 + 27 Plugin-UMDs | `EditorJsService` / `EditorJsAssetService` |
| `tabler` | 1.6.1 (`tabler.min.css`/`tabler.min.js` ohne `sourceMappingURL`-Kommentar) | Admin-/Member-Partials, `js/tabler-bootstrap-bridge.js` |
| `photoswipe` | 5.4.4 (ESM-Build) | `Bootstrap.php` (Customizer `performance.enable_photoswipe`), `js/photoswipe-init.js` |
| `tabler-icons` | 3.48.0 | Admin-Header (lokal) |
| `htmlpurifier` | 4.19.0 | eigener Autoloader in `assets/autoload.php`; `PurifierService` (Cache `cache/htmlpurifier`) |
| `cron` (poliander/cron) | 3.3.1 | `CronExpressionAdapter` |
| `twofactorauth` + `bacon-qr-code` | Snapshot | `Auth/MFA/TotpAdapter.php` |
| `webauthn` | Snapshot | `Auth/Passkey/WebAuthnAdapter.php` |
| `ldaprecord` | 4.0.8 | `Auth/LDAP/LdapAuthProvider.php` |
| `php-jwt` | 7.2.1 | `Services/JwtService.php` (von keiner Core-Route genutzt) |
| `melbahja-seo` | Snapshot | `SeoSchemaRenderer`, `SitemapService`, `IndexingService`, SEO-Suite |
| `mailer` / `mime` | 8.1.7 | `MailService` |
| `translation` / `yaml` | 8.1.5 / 8.1.8 | `TranslationService` (Kataloge `CMS/lang/*.yaml`) |
| `tntsearch` | 5.3.0 | `SearchService` (Index `cache/search/`); die Seitensuche `/search` nutzt seit 3.5.0 `SiteSearchService` |
| `Carbon` | 3.14.2 | `time_ago()` in `includes/functions/redirects-auth.php` |
| `suneditor` | 3.3.3 | `EditorService`, nur bei `setting_editor_type = suneditor` |
| `dompdf` | 3.1.6 | `CMS/vendor/dompdf/`, `PdfService` (z. B. PDF-Export im Router) |
| `images/` | 13 PNG + `plugin-not-found.svg` | Logos, Dashboard-Icons, Member-Platzhalter |

Nicht (mehr) im Repository: `cookieconsent`, `filepond`, `elfinder`, `gridjs`, `simplepie`, `msgraph` unter `CMS/assets/` sowie der Staging-Ordner `ASSETS/` im Repository-Root. Upstream-Updates werden seit 2026-10-04 als ZIP-Archive unter `CMS_ASSETS/` im Repository-Root abgelegt (nicht deployt). Die Unterordner-READMEs dieser Pakete wurden in `3.5.0` aus `DOC/assets/` entfernt.
