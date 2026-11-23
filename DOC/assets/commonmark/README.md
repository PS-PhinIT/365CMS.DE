# CommonMark-Bündel (`CMS/assets/commonmark/`)
> **Stand:** 2026-11-23 | **Version:** 4.2.0 | **Status:** Aktuell

Markdown-Renderer der Community (`CMS\Services\Community\Support\ContentRenderer`, PR #2b). Entscheidung A1 des Auftraggebers vom
2026-11-19: league/commonmark mit seinen Abhängigkeiten als **eine gescopte Bündeldatei** statt als Ordnerkopie (2 statt 250 bis 385
Paketeinträge; keine Kollision mit Kopien derselben Bibliotheken in Plugins).

## Inhalt

| Paket | Version | dist-Referenz (`.github/deps/composer.lock`) | Lizenz |
|---|---|---|---|
| `league/commonmark` | 2.10.7 | `8eb53e906fd60c67038d22f1f1cab420ed58b8be` | BSD-3-Clause |
| `league/config` | 1.2.0 | `754b3604fb2984c71f4af4a9cbe7b57f346ec1f3` | BSD-3-Clause |
| `dflydev/dot-access-data` | 3.0.3 | `a23a2bf4f31d3518f3ecb38660c95715dfead60f` | MIT |
| `nette/schema` | 1.4.0 | `af9fa8a86bfca9b02b10346220db26e62ef942ec` | BSD-3-Clause (gewählt aus BSD-3-Clause, GPL-2.0-only, GPL-3.0-only) |
| `nette/utils` | 4.1.6 | `6bf24518accf32cf845bca305e0b92078700fd01` | BSD-3-Clause (gewählt aus BSD-3-Clause, GPL-2.0-only, GPL-3.0-only) |

Nicht im Bündel: `psr/event-dispatcher` (liegt unter `CMS/assets/psr/EventDispatcher/`), `symfony/deprecation-contracts`
(in `symfony/contracts` unter `CMS/assets/symfony-contracts/`), `symfony/polyfill-php80` (in `.github/deps/composer.json` per `replace`
ausgeblendet; PHP ≥ 8.4).

| Datei | Bytes | SHA-256 (über LF-normalisierte Bytes) |
|---|---|---|
| `commonmark.php` | 421526 | `b2006b157eba4a7e56fdccab4d94c2af17cb1e48f93c674d04198bd55b3a88b2` |
| `LICENSE` | 10849 | `16f9259747ad17a5970745cc98a42df5a4d1e395540f1bfe33ae3686147e4995` |

- **Präfix:** alle Namensräume liegen unter `CMS\Vendor\` (z. B. `CMS\Vendor\League\CommonMark\MarkdownConverter`); Importe und voll
  qualifizierte Namen sind umgeschrieben. Ohne Präfix führt jede fremde Kopie von nette/utils im Prozess zum Fatal Error
  („Cannot declare trait Nette\StaticClass“, gemessen im Entwurf render §9).
- **Umfang:** transitive Hülle ab den Wurzeln des Build-Skripts (`MarkdownConverter`, `Environment`, die fünf Erweiterungen
  `CommonMarkCoreExtension`, `TableExtension`, `StrikethroughExtension`, `AutolinkExtension`, `TaskListExtension`) und jeder Einfuhr
  `use CMS\Vendor\…;` in `ContentRenderer.php` (`--roots-from`): **240 von 375 Dateien**, 258 Typen, Kommentare entfernt
  (gzip etwa 72 KB). Die Lizenztexte stehen vollständig in `LICENSE`, der Kopf des Bündels nennt Pakete, Versionen, dist-Referenzen und Urheber.
- **Form:** ein `declare(strict_types=1);`, ein Wächter `namespace { if (!\defined('ABSPATH')) { return; } }`, danach je Quelldatei ein
  geklammerter Block `namespace CMS\Vendor\… { … }` in topologischer Reihenfolge (Eltern vor Kindern, stabil nach Pfad).
- **Laden:** `CMS/assets/autoload.php` lädt die Datei beim ersten Zugriff auf eines der Präfixe `CMS\Vendor\League\CommonMark\`,
  `CMS\Vendor\League\Config\`, `CMS\Vendor\Dflydev\DotAccessData\` oder `CMS\Vendor\Nette\`. `CMS/core/VendorRegistry.php` führt das
  Bündel unter `league/commonmark` mit einer Funktionsprobe.
- **Voraussetzungen:** PHP ab 8.2 (nette/utils; das CMS verlangt 8.4.1), `ext-mbstring` (Core-Polyfill vorhanden).

## Bauen und prüfen

1. Leeres Verzeichnis, darin genau die Stände der Lockdatei installieren (git-Quellen genügen):
   `composer require --no-plugins --no-scripts --prefer-source league/commonmark:2.10.7 league/config:1.2.0 dflydev/dot-access-data:3.0.3 nette/schema:1.4.0 nette/utils:4.1.6`
2. Aus dem Repository-Wurzelverzeichnis bauen:
   ```
   php .github/deps/build-commonmark-bundle.php --vendor=<verzeichnis>/vendor --lock=.github/deps/composer.lock \
       --out=CMS/assets/commonmark/commonmark.php --license=CMS/assets/commonmark/LICENSE --prefix='CMS\Vendor' --guard \
       --roots-from=CMS/core/Services/Community/Support/ContentRenderer.php
   ```
   Das Skript bricht ab, wenn Version oder dist-Referenz eines Pakets im Vendor-Verzeichnis von der Lockdatei abweicht, eine Quelle ohne
   `declare(strict_types=1)` ist oder Klassennamen als Zeichenkette enthält. Zwei Läufe ergeben dieselben Bytes.
3. Reproduzierbarkeit prüfen, ohne zu schreiben: derselbe Aufruf mit `--verify` (Exit 0 = byte-gleich). Die automatischen Tests prüfen das
   ebenfalls, wenn `CMS_TEST_COMMONMARK_VENDOR=<verzeichnis>/vendor` gesetzt ist (sonst nur ein Hinweis).
4. Prüfsummen und Bytes oben eintragen; `ContentRenderer::VERSION` erhöhen und den Fingerabdruck der Render-Tests (`CR_FINGERPRINTS`)
   ergänzen, denn das HTML im Cache hängt vom Bündel ab.
5. Bei einem Versionswechsel zusätzlich die Pins in `.github/deps/composer.json` und die Lockdatei
   (`composer update --no-install --ignore-platform-reqs league/commonmark league/config dflydev/dot-access-data nette/schema nette/utils`
   in `.github/deps/`), die Zeilen in `DOC/assets/README.md` und `ASSET.md` sowie die Tabelle oben anpassen.

Automatisch geprüft werden am Bündel: Pins, `replace`, Kopfzeilen = Lockdatei, Prüfsummen, genau zwei Dateien, nur Namensräume
`CMS\Vendor\…`, Lizenz nennt alle fünf Pakete, `.gitattributes`; dazu Laden über den Assets-Autoloader, keine globalen Klassen, jede
Einfuhr vorhanden, Konfiguration im Schema und der XSS-Korpus des Renderers.

`.gitattributes` führt `CMS/assets/commonmark/* -text`: Git schreibt die Zeilenenden der generierten Dateien nie um; die Tests
normalisieren CRLF zusätzlich.
