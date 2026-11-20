<?php
/**
 * Community-Darstellung (PR #2b; Vertrag DESIGN-2b §4.7, §7.1, Entwurf render §6.1): Renderer-Kern `Support\ContentRenderer`,
 * Purifier-Profil `community`, CommonMark-Bündel, Zitatregel `QuoteService::markerId()` und Ausgaberegel `QuoteService::presentable()`.
 *
 * Abschnitte (Besitzer): A Bündel, B Darstellung R1 bis R10 mit „Pathologie“, D Zählung, E Purifier-Profil, F Version, I Sprache (V, W1);
 * G `RenderService` (R, W2) und H Dienste mit Renderer (T, W3, `part-services.php`). C (XSS-Korpus) liegt in `TESTS/community-xss`.
 *
 * DB-frei. Die Pathologie-Fälle laufen in einem eigenen Prozess (`php run.php --pathology`), damit die Speicherspitze je Fall
 * messbar ist und eine Mutante ohne Grenzen den Prüflauf nicht mit Speichermangel abbricht (der Elternprozess wertet die Zeilen aus).
 *
 *   php TESTS/community-render/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
define('SITE_URL', 'https://forum.example');

// PHP-Zone der Suite: Europe/Berlin, damit jede Verwendung von date() statt gmdate() auffällt.
date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

// Gegenprobe-Haken (TESTS/lib/Mutant.php): mit CMS_TEST_MUTANT=<name> lädt der Autoloader eine Mutante statt des Originals.
require_once dirname(__DIR__) . '/lib/Mutant.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        Mutant::load($file);
    }
});
// Bündel (CMS\Vendor\…), HTMLPurifier und Symfony\Yaml über den produktiven Assets-Autoloader.
require_once ABSPATH . 'assets/autoload.php';

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/CommunityTestDatabase.php';

use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;
use CMS\Services\PurifierService;

T::$verbose = in_array('-v', $argv ?? [], true);

Settings::setInstance(Settings::fromArray(['base_path' => 'forum']));

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// Hilfen
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Anhangszeilen der Suite (Form wie `AttachmentRepository::findMany()`): Beitrag 10 gehört Mitglied 7.
 *
 * @return array<int, array<string, mixed>>
 */
function crRows(): array
{
    return [
        5 => ['id' => 5, 'post_id' => 10, 'user_id' => 7, 'mime_type' => 'image/png', 'width' => 640, 'height' => 480, 'has_preview' => 1],
        6 => ['id' => 6, 'post_id' => null, 'user_id' => 7, 'mime_type' => 'image/jpeg', 'width' => null, 'height' => null, 'has_preview' => 0],
        7 => ['id' => 7, 'post_id' => null, 'user_id' => 8, 'mime_type' => 'image/jpeg', 'width' => 10, 'height' => 10, 'has_preview' => 0],
        8 => ['id' => 8, 'post_id' => 11, 'user_id' => 7, 'mime_type' => 'image/png', 'width' => 10, 'height' => 10, 'has_preview' => 1],
        9 => ['id' => 9, 'post_id' => 10, 'user_id' => 7, 'mime_type' => 'application/pdf', 'width' => null, 'height' => null, 'has_preview' => 0],
    ];
}

/**
 * Renderer mit den Anhangszeilen der Suite; `$lookups` sammelt die nachgeschlagenen ID-Listen.
 *
 * @param array<int, list<int>>|null $lookups
 */
function crRenderer(?\ArrayObject $lookups = null, ?\Closure $purify = null, ?\Closure $headroom = null, ?\Closure $available = null): ContentRenderer
{
    $rows = crRows();

    return new ContentRenderer(
        purify: $purify,
        attachments: static function (array $ids) use ($rows, $lookups): array {
            $lookups?->append($ids);

            return array_intersect_key($rows, array_flip($ids));
        },
        available: $available,
        headroom: $headroom,
    );
}

/** HTML eines Textes mit Beitrag 10 und Schreiber 7 (`render()['html']`). */
function crHtml(string $markdown, array $options = ['post_id' => 10, 'uploader_id' => 7]): ?string
{
    return crRenderer()->render($markdown, $options)['html'];
}

/** Erwartete Zählung: leere Zählung mit überschriebenen Feldern. */
function crStats(array $fields): array
{
    return array_replace(ContentRenderer::emptyStats(), $fields);
}

/**
 * Pathologie-Fälle (Vertrag §4.7 Nr. 3, Fassung 3.1): Erzeuger und Erwartung.
 *   pre    vor dem Parsen abgelehnt (Speicherschätzung nie gefragt)
 *   post   nach dem Parsen abgelehnt (Strukturgrenze)
 *   render gerendert mit Spitze < 64 MiB
 *   any    abgelehnt oder gerendert mit Spitze < 64 MiB
 *
 * @return array<string, array{0: \Closure(): string, 1: string}>
 */
function crPathologyCases(): array
{
    $rep = static fn (string $unit, int $n): string => str_repeat($unit, $n);
    $dense = static fn (int $repeat, int $chars): string => mb_substr(str_repeat(
        "Ein normaler Absatz mit **fett**, _kursiv_, `code` und einem [Link](https://example.org/a) sowie Text.\n\n```php\n"
        . str_repeat("\$a = \$b['x'] * \$c_d; // <tag> | pipe\n", 20) . "```\n\n" . str_repeat("- Punkt mit *Betonung*\n  - Unterpunkt\n", 10)
        . "\n| A | B | C |\n|---|---|---|\n" . str_repeat("| x | y | z |\n", 10) . "\n> [quote=post:5]\n> zitiert\n\n",
        $repeat
    ), 0, $chars);

    return [
        // die sechs Eingaben der Kritik
        'kritik-klammern-200k' => [static fn (): string => $rep('[', 200000), 'pre'],
        'kritik-tabelle-300x300' => [static fn (): string => '|' . $rep('a|', 300) . "\n|" . $rep('-|', 300) . "\n" . $rep($rep('x|', 300) . "\n", 300), 'post'],
        'kritik-tabellenzeilen-33334' => [static fn (): string => "|a|b|\n|-|-|\n" . $rep("|x|y|\n", 33334), 'pre'],
        'kritik-zeilen-100k' => [static fn (): string => $rep("x\n", 100000), 'pre'],
        'kritik-listenpunkte-50k' => [static fn (): string => $rep("- x\n", 50000), 'pre'],
        'kritik-tabellen-50000-zeichen' => [static fn (): string => mb_substr("|a|b|c|\n|-|-|-|\n" . $rep("|x|y|z|\n", 7000), 0, 50000), 'post'],
        // Fassung 3.1: Tag-Anfänge zählen als Begrenzer
        'tags-b-24000' => [static fn (): string => $rep('<b>', 24000), 'pre'],
        'tags-b-66666' => [static fn (): string => $rep('<b>', 66666), 'pre'],
        'tags-ax-50000' => [static fn (): string => $rep('<a>x', 50000), 'pre'],
        'tags-b-leer-50000' => [static fn (): string => $rep('<b> ', 50000), 'pre'],
        'tags-b-20000' => [static fn (): string => $rep('<b>', 20000), 'render'],
        'tags-schluss-20000' => [static fn (): string => $rep('</b>', 20000), 'render'],
        'kleiner-als-30000' => [static fn (): string => $rep('a < b ', 30000), 'render'],
        'html-codeblock-50000' => [static fn (): string => "```html\n" . mb_substr($rep('<div class="row"><span>Text</span> <a href="/x">Link</a></div>' . "\n", 900), 0, 50000) . "```\n", 'render'],
        'dicht-50000' => [static fn (): string => $dense(200, 50000), 'render'],
        // Grenzfälle (Klammern, Paare, Betonung, Entities, Backticks, Listen, Überschriften, Tabellen, Autolinks, Mail-Links, Zitate, Referenzen, Klammern im Ziel)
        'klammern-20000' => [static fn (): string => $rep('[', 20000), 'any'],
        'paare-10000' => [static fn (): string => $rep('[a]', 10000), 'any'],
        'aufgaben-10000' => [static fn (): string => $rep("- [ ] x\n", 10000), 'any'],
        'betonung-20000' => [static fn (): string => $rep('*a _b ', 10000), 'any'],
        'entities-20000' => [static fn (): string => $rep('&lt;', 20000), 'any'],
        'backticks-20000' => [static fn (): string => $rep('`a', 20000), 'any'],
        'liste-flach-8300' => [static fn (): string => $rep("- x\n", 8300), 'any'],
        'zeilen-12400' => [static fn (): string => $rep("x\n", 12400), 'any'],
        'ueberschriften-12400' => [static fn (): string => $rep("# h\n", 12400), 'any'],
        'zitatzeilen-19999' => [static fn (): string => $rep("> x\n", 19999), 'any'],
        'tabelle-5000' => [static fn (): string => "|a|b|\n|-|-|\n" . $rep("|x|y|\n", 2499), 'any'],
        'tabelle-breit' => [static fn (): string => '|' . $rep('a|', 100) . "\n|" . $rep('-|', 100) . "\n" . $rep($rep('x|', 100) . "\n", 49), 'any'],
        'www-12400' => [static fn (): string => $rep('www.a.de ', 12400), 'any'],
        'mail-28000' => [static fn (): string => $rep('a@b.de ', 28000), 'any'],
        'gemischt' => [static fn (): string => $rep("- [a] *b* x\n", 5000) . $rep("x\n", 9000), 'any'],
        'zitate-6600' => [static fn (): string => $rep("> [quote=post:5]\n> x\n\n", 6600), 'any'],
        'referenzen-9000' => [static fn (): string => "[r]: https://x.example/r\n" . $rep('[r] ', 9000), 'any'],
        'backslash-9000' => [static fn (): string => $rep('\\*', 9000), 'any'],
        'harte-umbrueche-19000' => [static fn (): string => $rep("x  \n", 19000), 'any'],
        'autolinks-spitz-15000' => [static fn (): string => $rep('<http://a.b> ', 15000), 'any'],
        'klammern-im-ziel' => [static fn (): string => '[a](' . $rep('(', 190000) . ')', 'any'],
        'klammern-im-ziel-paare' => [static fn (): string => $rep('[a](' . $rep('(', 30) . 'x' . $rep(')', 30) . ') ', 2500), 'any'],
        'ziel-spitz-3000' => [static fn (): string => $rep('[a](<' . $rep('x', 50) . '>) ', 3000), 'any'],
        'bilder-verschachtelt' => [static fn (): string => $rep('![![![a](b)](c)](d) ', 2000), 'any'],
        'zitat-lazy-19000' => [static fn (): string => '> ' . $rep("a\n", 19000), 'any'],
        'liste-tief-19000' => [static fn (): string => implode("\n", array_map(static fn (int $i): string => $rep('  ', $i % 20) . '1. x', range(1, 19000))), 'any'],
        'html-bloecke-6600' => [static fn (): string => $rep("<div>\nx\n\n", 6600), 'any'],
        'liste-verschachtelt-200k' => [static fn (): string => mb_substr(implode("\n", array_map(static fn (int $i): string => $rep('  ', $i % 40) . '- x', range(1, 40000))), 0, 200000), 'any'],
        'zitate-9000' => [static fn (): string => $rep("> [quote=post:5]\n> x\n\n", 9000), 'any'],
        'links-8000' => [static fn (): string => $rep('[a](https://x.example/p) ', 8000), 'any'],
        'betonung-200x140' => [static fn (): string => $rep($rep('*a **b ', 140) . "\n", 200), 'any'],
        'tabs-19000' => [static fn (): string => $rep("\tcode\n", 19000), 'any'],
        'tags-attribute-20000' => [static fn (): string => $rep('<a href="https://x.example/p" title="t">', 20000), 'any'],
        'tags-klammern' => [static fn (): string => $rep('<b>[a]', 5000), 'any'],
        'tags-betonung' => [static fn (): string => $rep('<i>*a* ', 6600), 'any'],
        'tags-tabelle' => [static fn (): string => "|a|b|\n|-|-|\n" . $rep("|<b>x|<i>y|\n", 2400), 'any'],
        'tags-liste' => [static fn (): string => $rep("- <b>x\n", 19000), 'any'],
        'tags-kommentare' => [static fn (): string => $rep('<!-- -->', 20000), 'any'],
        'tags-deklarationen' => [static fn (): string => $rep('<!X>', 20000), 'any'],
        'tags-autolinks' => [static fn (): string => $rep('<https://a.example/x> ', 20000), 'any'],
        // Nachbesserung N1: `\`, `www.` und `@` zählen als Begrenzer (je Treffer ein Eintrag in `InlineParserEngine::matchParsers()`)
        'backslash-paare-100000' => [static fn (): string => $rep('\\\\', 100000), 'pre'],
        'backslash-paare-25000' => [static fn (): string => $rep('\\\\', 25000), 'pre'],
        'backslash-satz-25000' => [static fn (): string => $rep('\\!', 25000), 'pre'],
        'backslash-paare-10000' => [static fn (): string => $rep('\\\\', 10000), 'render'],
        'www-50000' => [static fn (): string => $rep('www.', 50000), 'pre'],
        'www-20000' => [static fn (): string => $rep('WwW.', 20000), 'any'],
        'mail-33000' => [static fn (): string => $rep('a@b.c,', 33000), 'pre'],
        'mail-20000' => [static fn (): string => $rep('a@b.c,', 20000), 'any'],
    ];
}

/**
 * Speicherschätzung eines Textes laut Vertrag §4.7 Nr. 1, unabhängig vom Code gezählt: Begrenzer sind `[`, `]`, `*`, `_`, `~`, `` ` ``,
 * `&`, `@`, `\`, jeder Tag-Anfang und jedes `www.` (ohne Groß-/Kleinschreibung).
 */
function crNeed(string $markdown): int
{
    $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
    $counts = count_chars($markdown, 1);
    $delimiters = (int) preg_match_all('~<[A-Za-z/!?]~', $markdown) + (int) preg_match_all('~www\.~i', $markdown);
    foreach (str_split('[]*_~`&@\\') as $char) {
        $delimiters += $counts[ord($char)] ?? 0;
    }

    return ContentRenderer::memoryNeed(strlen($markdown), substr_count($markdown, "\n") + 1, $delimiters, $counts[ord('|')] ?? 0);
}

// ── Kindprozess der Pathologie: je Fall eine JSON-Zeile, dann Ende (keine Abschnitte, keine Teildateien) ─────────────────────

if (in_array('--pathology', $argv ?? [], true)) {
    Mutant::$requireApplied = false;
    $calls = 0;
    $renderer = new ContentRenderer(headroom: static function () use (&$calls): ?int {
        $calls++;

        return null;
    });
    $renderer->render('Aufwärmen mit **fett** und [Link](https://x.example)');
    foreach (crPathologyCases() as $name => [$make]) {
        $markdown = $make();
        $calls = 0;
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $started = hrtime(true);
        $result = $renderer->render($markdown);
        echo json_encode([
            'name' => $name,
            'bytes' => strlen($markdown),
            'ok' => $result['ok'],
            'html' => $result['html'] !== null,
            'peak' => memory_get_peak_usage() - $before,
            'ms' => (hrtime(true) - $started) / 1e6,
            'prechecked' => $calls,
        ]) . "\n";
        unset($markdown, $result);
    }
    exit(0);
}

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// A Bündel
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('A Bündel: gescopte Klassen laden über den Assets-Autoloader, keine globalen Kopien, Einfuhren und Konfiguration', static function (): void {
    T::check(is_file(ABSPATH . 'assets/commonmark/commonmark.php'), 'CMS/assets/commonmark/commonmark.php liegt vor');
    T::check(class_exists('CMS\\Vendor\\League\\CommonMark\\MarkdownConverter'), 'CMS\\Vendor\\League\\CommonMark\\MarkdownConverter lädt über den Assets-Autoloader');
    T::check(in_array(realpath(ABSPATH . 'assets/commonmark/commonmark.php'), array_map('realpath', get_included_files()), true), 'geladen wurde das Bündel commonmark/commonmark.php');
    foreach (['League\\CommonMark\\MarkdownConverter', 'League\\Config\\Configuration', 'Dflydev\\DotAccessData\\Data', 'Nette\\Utils\\Strings', 'Nette\\Schema\\Expect'] as $global) {
        T::check(!class_exists($global, false), 'keine globale Klasse ' . $global . ' (Präfix CMS\\Vendor\\)');
    }
    foreach (['CMS\\Vendor\\Nette\\Utils\\Strings', 'CMS\\Vendor\\League\\Config\\Configuration', 'CMS\\Vendor\\Dflydev\\DotAccessData\\Data'] as $scoped) {
        T::check(class_exists($scoped), 'gescopt vorhanden: ' . $scoped);
    }

    // Jede Einfuhr `use CMS\Vendor\…;` unter Services/Community/** liegt im Bündel (die Hülle des Build-Skripts folgt diesen Einfuhren).
    $imports = [];
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ABSPATH . 'core/Services/Community', \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && preg_match_all('/^use\s+(CMS\\\\Vendor\\\\[A-Za-z0-9_\\\\]+)\s*;/m', (string) file_get_contents($file->getPathname()), $m) > 0) {
            foreach ($m[1] as $name) {
                $imports[$name] = basename($file->getPathname());
            }
        }
    }
    T::check(count($imports) >= 20, 'Einfuhren aus CMS\\Vendor\\ gefunden (' . count($imports) . ')');
    $missing = array_keys(array_filter($imports, static fn (string $file, string $name): bool => !class_exists($name) && !interface_exists($name) && !trait_exists($name), ARRAY_FILTER_USE_BOTH));
    T::same([], $missing, 'jede Einfuhr CMS\\Vendor\\… unter Services/Community/** existiert im Bündel');

    // Jeder Schlüssel aus CONFIG steht im Schema der Umgebung, gelesen = gesetzt (CommonMark ignoriert Tippfehler sonst nicht immer laut).
    $renderer = new ContentRenderer();
    $environment = (new \ReflectionMethod($renderer, 'environment'))->invoke($renderer);
    $config = (new \ReflectionClassConstant(ContentRenderer::class, 'CONFIG'))->getValue();
    $flat = [];
    $walk = static function (array $values, string $prefix) use (&$walk, &$flat): void {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '/' . $key;
            if (is_array($value) && !array_is_list($value)) {
                $walk($value, $path);
            } else {
                $flat[$path] = $value;
            }
        }
    };
    $walk($config, '');
    T::check(count($flat) >= 11, 'CONFIG hat mindestens elf Blätter (' . count($flat) . ')');
    $reader = $environment->getConfiguration();
    $wrong = [];
    foreach ($flat as $path => $value) {
        if (!$reader->exists($path) || $reader->get($path) !== $value) {
            $wrong[] = $path;
        }
    }
    T::same([], $wrong, 'jeder Konfigurationsschlüssel existiert im Schema und hat den gesetzten Wert');
    T::check(!$reader->exists('renderer/soft_breaks'), 'Gegenprobe: ein Tippfehler existiert nicht im Schema');
    T::same(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20], array_intersect_key($config, array_flip(['html_input', 'allow_unsafe_links', 'max_nesting_level'])), 'Sicherheitswerte: Roh-HTML escaped, unsichere Links ohne href, Schachtelgrenze 20');
    T::same(true, $renderer->isAvailable(), 'isAvailable(): Bündel ladbar');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// B Darstellung (R1 bis R10)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('B Darstellung: Überschriften, Betonung, Listen, Aufgaben, Code, Tabellen, Umbrüche, Roh-HTML (R1 bis R3, R8 bis R10)', static function (): void {
    T::same("<h3>Eins</h3>\n<h4>Zwei</h4>\n<h4>Drei</h4>\n<h3>Setext</h3>\n<h4>Zwei</h4>\n", crHtml("# Eins\n## Zwei\n### Drei\nSetext\n===\nZwei\n---"), 'R2: # → h3, ## und tiefer → h4 (ATX und Setext)');
    T::same("<p><strong>fett</strong> <em>kursiv</em> <del>weg</del></p>\n", crHtml('**fett** *kursiv* ~~weg~~'), 'fett, kursiv, durchgestrichen');
    T::same("<ul>\n<li>a\n<ul>\n<li>b</li>\n</ul>\n</li>\n<li>c</li>\n</ul>\n<ol>\n<li>\n<p>x</p>\n</li>\n<li>\n<p>y</p>\n</li>\n</ol>\n", crHtml("- a\n  - b\n- c\n\n1. x\n\n2. y"), 'Listen verschachtelt und locker');
    T::same("<ol start=\"3\">\n<li>drei</li>\n</ol>\n", crHtml('3. drei'), 'nummerierte Liste mit Start');
    T::same("<ul>\n<li><span class=\"cms-task\">☐</span> offen</li>\n<li><span class=\"cms-task cms-task--done\">☑</span> fertig</li>\n</ul>\n", crHtml("- [ ] offen\n- [x] fertig"), 'R8: Aufgabenliste als span, kein input');
    T::check(!str_contains((string) crHtml("- [x] fertig"), '<input'), 'R8: kein <input> in der Aufgabenliste');

    T::same("<pre><code class=\"language-php\">&lt;?php echo 1;\n</code></pre>\n", crHtml("```PHP\n<?php echo 1;\n```"), 'R3: Sprache klein geschrieben, Inhalt escaped');
    T::same("<pre><code class=\"language-javascript\">x\n</code></pre>\n", crHtml("```js\nx\n```"), 'R3: Alias js → javascript');
    T::same("<pre><code class=\"language-csharp\">x\n</code></pre>\n", crHtml("```C# und mehr\nx\n```"), 'R3: Alias C# → csharp, nur das erste Wort zählt');
    T::same("<pre><code class=\"language-plaintext\">x\n</code></pre>\n", crHtml("```txt\nx\n```"), 'R3: Alias txt → plaintext');
    T::same("<pre><code>x\n</code></pre>\n", crHtml("```unbekannt\nx\n```"), 'R3: unbekannte Sprache ohne Klasse');
    T::same("<pre><code>x\n</code></pre>\n", crHtml("```php\"><script>alert(1)</script>\nx\n```"), 'R3: Info-String-Injektion ergibt keine Klasse');
    T::same("<pre><code>eingerückt\n</code></pre>\n", crHtml('    eingerückt'), 'R3: eingerückter Code');
    T::same("<p><code>&lt;b&gt;</code></p>\n", crHtml('`<b>`'), 'Code-Span escaped');

    T::same(
        "<table>\n<thead>\n<tr>\n<th class=\"text-start\">L</th>\n<th class=\"text-center\">M</th>\n<th class=\"text-end\">R</th>\n</tr>\n</thead>\n<tbody>\n<tr>\n<td class=\"text-start\">a</td>\n<td class=\"text-center\"></td>\n<td class=\"text-end\">c</td>\n</tr>\n</tbody>\n</table>\n",
        crHtml("| L | M | R |\n|:--|:-:|--:|\n| a |  | c |"),
        'R9: Ausrichtung als Klassen, leere Zelle bleibt'
    );
    T::same("<p>Zeile eins<br />\nZeile zwei</p>\n", crHtml("Zeile eins\nZeile zwei"), 'R10: weicher Umbruch → <br />');
    T::same("<p>&lt;b&gt;fett?&lt;/b&gt; &lt;script&gt;x&lt;/script&gt;</p>\n&lt;div&gt;block&lt;/div&gt;\n", crHtml("<b>fett?</b> <script>x</script>\n\n<div>block</div>"), 'R1: Roh-HTML wird sichtbar escaped (Inline und Block)');
    T::same("<hr />\n", crHtml('---'), 'Trennlinie');

    $deep = (string) crHtml(str_repeat('>', 60) . ' tief');
    T::same(20, substr_count($deep, '<blockquote>'), '60 × „>“ ergibt höchstens 20 Ebenen (max_nesting_level)');
});

T::run('B Darstellung: Links und Autolinks (R4), Bilder und Anhänge (R5), base_path', static function (): void {
    T::same(
        "<p><a href=\"https://example.org/a\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">ext</a> <a href=\"https://forum.example/b\">int</a> <a href=\"mailto:a@b.de\">mail</a> <a href=\"/c\">rel</a> <a href=\"#d\">anker</a> <a href=\"https://evil.example/x\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">proto</a> <a>ftp</a></p>\n",
        crHtml('[ext](https://example.org/a) [int](https://forum.example/b) [mail](mailto:a@b.de) [rel](/c) [anker](#d) [proto](//evil.example/x) [ftp](ftp://x.example)'),
        'R4: extern mit nofollow ugc und _blank, intern ohne, mailto, relativ, Anker, //host → https://host mit ugc, ftp:// ohne Ziel'
    );
    T::same(
        "<p><a href=\"https://www.example.org\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">www.example.org</a> und <a href=\"mailto:a@b.de\">a@b.de</a> und <a href=\"https://x.example/y\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">https://x.example/y</a> und ftp://f.example</p>\n",
        crHtml('www.example.org und a@b.de und https://x.example/y und ftp://f.example'),
        'Autolinks: www. → https, E-Mail → mailto, ftp:// wird kein Link'
    );
    T::same("<p><a href=\"https://example.org/a\" title=\"Titel\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">t</a></p>\n", crHtml('[t](https://example.org/a "Titel")'), 'R4: Titel bleibt');
    T::same("<p><a>js</a></p>\n", crHtml('[js](javascript:alert(1))'), 'R4: unsicheres Ziel ohne href');

    T::same(
        "<p><img src=\"/forum/anhang/5/vorschau\" alt=\"Bild\" width=\"640\" height=\"480\" loading=\"lazy\" decoding=\"async\"> <img src=\"/forum/anhang/6\" alt=\"eigen\" loading=\"lazy\" decoding=\"async\"> fremd anderer <a href=\"/forum/anhang/9\">pdf</a> <a href=\"https://img.example/p.png\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">extern</a> data js <a href=\"https://cdn.example/x.png\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">https://cdn.example/x.png</a></p>\n",
        crHtml('![Bild](attachment:5) ![eigen](attachment:6) ![fremd](attachment:7) ![anderer](attachment:8) ![pdf](attachment:9) ![extern](https://img.example/p.png) ![data](data:image/png;base64,AAA) ![js](javascript:alert(1)) ![](//cdn.example/x.png)'),
        'R5: Anhang des Beitrags mit Maßen und Vorschau, unverknüpft eigener, unverknüpft fremder → Text, anderer Beitrag → Text, PDF → Link, extern → Link, data:/javascript: → Text, //host → Link'
    );
    T::same("<p>Bild</p>\n", crHtml('![Bild](attachment:5)', ['uploader_id' => 7]), 'R5: ohne post_id ist der verknüpfte Anhang eines Beitrags nicht gültig');
    T::same("<p>eigen</p>\n", crHtml('![eigen](attachment:6)', ['post_id' => 10]), 'R5: ohne uploader_id (Nachrendern) ist ein unverknüpfter Upload nicht gültig');
    T::same("<p>eigen</p>\n", crHtml('![eigen](attachment:6)', ['post_id' => 10, 'uploader_id' => 0]), 'R5: uploader_id 0 gilt nicht als Schreiber');
    T::same("<p><a href=\"/forum/anhang/5\">Bild</a> <a href=\"/forum/anhang/5\">/forum/anhang/5</a></p>\n", crHtml('![Bild](attachment:5) ![](attachment:5)', ['post_id' => 10, 'images' => false]), 'images = false: Bild-Anhang als Link (Text = Alternativtext, sonst Adresse)');
    T::same(
        "<p><a href=\"/forum/anhang/9\">Datei</a> fremd null gross <a href=\"https://x.example\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\"><img src=\"/forum/anhang/5/vorschau\" alt=\"Bild\" width=\"640\" height=\"480\" loading=\"lazy\" decoding=\"async\"></a></p>\n",
        crHtml('[Datei](attachment:9) [fremd](attachment:7) [null](attachment:0) [gross](attachment:99999999999) [![Bild](attachment:5)](https://x.example)'),
        'R4: Anhangslink gültig → Datei-Adresse, ungültig → nur Text; Bild im Link'
    );
    T::same("<p><a href=\"https://x.example\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">extern</a></p>\n", crHtml('[![extern](https://img.example/p.png)](https://x.example)'), 'kein Link im Link: fremdes Bild im Link wird Text');

    $lookups = new \ArrayObject();
    $many = '';
    for ($id = 100; $id < 160; $id++) {
        $many .= '![a](attachment:' . $id . ') ';
    }
    crRenderer($lookups)->render($many, ['post_id' => 10]);
    T::same(1, count($lookups), 'Anhänge: eine Abfrage je Text');
    T::same(range(100, 149), $lookups[0] ?? null, 'höchstens MAX_ATTACHMENTS = 50 IDs werden nachgeschlagen (die kleinsten)');
    $none = new \ArrayObject();
    crRenderer($none)->render('ohne Anhang');
    T::same(0, count($none), 'ohne Anhang keine Abfrage');

    Settings::setInstance(Settings::fromArray(['base_path' => 'community']));
    try {
        T::same("<p><img src=\"/community/anhang/5/vorschau\" alt=\"x\" width=\"640\" height=\"480\" loading=\"lazy\" decoding=\"async\" /></p>\n", crHtml('![x](attachment:5)'), 'base_path = community → /community/anhang/5/vorschau');
    } finally {
        Settings::setInstance(Settings::fromArray(['base_path' => 'forum']));
    }
});

T::run('B Darstellung: Zitat-Blöcke (R6), Spoiler (R7)', static function (): void {
    T::same("<blockquote class=\"cms-quote\" data-quote-post=\"5\">\n<p>Text der Quelle</p>\n</blockquote>\n<p>Antwort</p>\n", crHtml("> [quote=post:5]\n> Text der Quelle\n\nAntwort"), 'R6: Markerzeile entfernt, Klasse und Kennzeichen gesetzt');
    T::same("<blockquote class=\"cms-quote\" data-quote-post=\"5\">\n<p>Text</p>\n</blockquote>\n", crHtml("> [quote=post:5]   \n>\n> Text"), 'R6: Leerraum hinter dem Marker, leerer erster Absatz entfällt');
    T::same("<blockquote class=\"cms-quote\" data-quote-post=\"5\">\n<p>erste<br />\nfortgesetzt</p>\n</blockquote>\n", crHtml("> [quote=post:5]\n> erste\nfortgesetzt"), 'R6: Fortsetzungszeile ohne „>“ gehört zum Block');
    T::same("<blockquote>\n<p>[quote=post:9]<br />\nx</p>\n</blockquote>\n", crHtml("> \\[quote=post:9]\n> x"), 'R6: entschärfter Marker ist kein Zitat-Block');
    T::same("<pre><code>&gt; [quote=post:9]\n</code></pre>\n", crHtml("```\n> [quote=post:9]\n```"), 'R6: Marker im Code ist kein Zitat-Block');
    T::same("<ul>\n<li>\n<blockquote>\n<p>[quote=post:9]</p>\n</blockquote>\n</li>\n</ul>\n", crHtml('- > [quote=post:9]'), 'R6: Marker in einer Liste ist kein Zitat-Block');
    T::same("<blockquote>\n<blockquote>\n<p>[quote=post:9]<br />\ny</p>\n</blockquote>\n</blockquote>\n", crHtml("> > [quote=post:9]\n> > y"), 'R6: Marker in einem verschachtelten Zitat ist kein Zitat-Block');
    T::same("<blockquote>\n<p><strong>[quote=post:9]</strong></p>\n</blockquote>\n", crHtml('> **[quote=post:9]**'), 'R6: fett gesetzter Marker ist kein Zitat-Block (Quellzeile entscheidet)');
    T::same("<blockquote class=\"cms-quote\" data-quote-post=\"5\">\n<p>a</p>\n<blockquote>\n<p>innen</p>\n</blockquote>\n</blockquote>\n", crHtml("> [quote=post:5]\n> a\n>\n> > innen"), 'R6: verschachteltes Zitat bleibt Teil des Blocks');

    T::same("<details class=\"cms-spoiler\">\n<summary>Spoiler</summary>\n<p>geheim</p>\n</details>\n", crHtml('>! geheim'), 'R7: Spoiler auf oberster Ebene');
    T::same("<details class=\"cms-spoiler\">\n<summary>Spoiler</summary>\n<p>geheim zwei</p>\n</details>\n", crHtml(">!\n> geheim zwei"), 'R7: „>!“ allein, Text in der nächsten Zeile');
    T::same("<blockquote>\n<p>außen</p>\n<details class=\"cms-spoiler\">\n<summary>Spoiler</summary>\n<p>innen</p>\n</details>\n</blockquote>\n", crHtml("> außen\n> >! innen"), 'R7: Spoiler im Zitat');
    T::same("<blockquote>\n<p>! kein</p>\n</blockquote>\n", crHtml('> ! kein'), 'R7: „> !“ mit Leerzeichen ist kein Spoiler');
    T::same("<ul>\n<li>\n<blockquote>\n<p>! liste</p>\n</blockquote>\n</li>\n</ul>\n", crHtml('- >! liste'), 'R7: in einer Liste kein Spoiler');
    T::same("<ul>\n<li>\n<p>a</p>\n<blockquote>\n<p>! eingerückt</p>\n</blockquote>\n</li>\n</ul>\n", crHtml("- a\n\n  >! eingerückt"), 'R7: eingerückt in der Fortsetzung eines Listenpunkts kein Spoiler');
    T::same("<blockquote>\n<p><img src=\"/forum/anhang/5/vorschau\" alt=\"x\" width=\"640\" height=\"480\" loading=\"lazy\" decoding=\"async\" /></p>\n</blockquote>\n", crHtml('>![x](attachment:5)'), 'R7: „>![“ ist ein Bild, kein Spoiler');
});

T::run('B Darstellung: Fehlerpfade (ungültiges UTF-8, Grenzen, Bündel fehlt, Purifier fehlt, verschachtelter Aufruf)', static function (): void {
    $failed = ['ok' => false, 'html' => null, 'version' => 0, 'stats' => ContentRenderer::emptyStats(), 'error' => 'render_failed'];
    T::same($failed, crRenderer()->render("a\xC3\x28"), 'ungültiges UTF-8 → render_failed');
    T::same($failed, crRenderer()->render(str_repeat('a', ContentRenderer::MAX_BYTES + 1)), 'mehr als MAX_BYTES → render_failed');
    $limit = crRenderer()->render(str_repeat('a', ContentRenderer::MAX_BYTES));
    T::same([true, ContentRenderer::VERSION], [$limit['ok'], $limit['version']], 'genau MAX_BYTES wird gerendert');
    T::same($failed, crRenderer()->render(str_repeat("\n", ContentRenderer::MAX_LINES)), 'mehr als MAX_LINES Zeilen → render_failed');
    T::same(true, crRenderer()->render(str_repeat("\n", ContentRenderer::MAX_LINES - 1))['ok'], 'genau MAX_LINES Zeilen werden gerendert');
    T::same($failed, crRenderer()->render(str_repeat('[', ContentRenderer::MAX_DELIMITERS + 1)), 'mehr als MAX_DELIMITERS Begrenzer → render_failed');
    T::same(true, crRenderer()->render(str_repeat('[', ContentRenderer::MAX_DELIMITERS))['ok'], 'genau MAX_DELIMITERS Begrenzer wird gerendert');
    T::same($failed, crRenderer(available: static fn (): bool => false)->render('**x**'), 'Bündel fehlt (available → false) → render_failed');
    T::same(false, crRenderer(available: static fn (): bool => false)->isAvailable(), 'isAvailable() folgt der Closure');

    $noPurifier = crRenderer(purify: static fn (string $html): ?string => null)->render('**x** [a](https://x.example)');
    T::same([true, null, 0], [$noPurifier['ok'], $noPurifier['html'], $noPurifier['version']], 'Purifier fehlt → ok, html null, version 0');
    T::same(crStats(['links' => 1, 'external_links' => 1, 'hosts' => ['x.example']]), $noPurifier['stats'], 'Purifier fehlt → die Zählung bleibt');
    $empty = crRenderer(purify: static fn (string $html): ?string => '')->render('**x**');
    T::same([true, null], [$empty['ok'], $empty['html']], 'R11: Purifier liefert „“ für nicht leeres HTML → html null');
    $throws = crRenderer(purify: static function (string $html): ?string {
        throw new \RuntimeException('kaputt');
    })->render('**x**');
    T::same([true, null, 0], [$throws['ok'], $throws['html'], $throws['version']], 'R12: Ausnahme im Purifier → html null');
    $raw = crRenderer(purify: static fn (string $html): ?string => $html)->render('');
    T::same([true, '', ContentRenderer::VERSION], [$raw['ok'], $raw['html'], $raw['version']], 'leerer Text → leeres HTML');

    $inner = null;
    $renderer = null;
    $renderer = new ContentRenderer(attachments: static function (array $ids) use (&$renderer, &$inner): array {
        $inner = $renderer->render('innen');

        return [];
    });
    $outer = $renderer->render('![x](attachment:5)');
    T::same($failed, $inner, 'R12: verschachtelter Aufruf (aus der Anhangs-Closure) → render_failed');
    T::same(true, $outer['ok'], 'der äußere Aufruf läuft weiter');
    T::same(true, $renderer->render('danach')['ok'], 'nach dem verschachtelten Aufruf ist der Renderer wieder frei');
    $boom = new ContentRenderer(attachments: static function (array $ids): array {
        throw new \RuntimeException('Datenbank weg');
    });
    T::same($failed, $boom->render('![x](attachment:5)'), 'Ausnahme beim Nachschlagen der Anhänge → render_failed');
    T::same(true, $boom->render('ohne Anhang')['ok'], 'danach rendert dieselbe Instanz weiter');

    T::same('<p class="cms-plain">&lt;b&gt;a&lt;/b&gt;<br />' . "\n" . '&quot;b&quot; &amp; &apos;c&apos;</p>', ContentRenderer::fallbackHtml("<b>a</b>\n\"b\" & 'c'"), 'fallbackHtml(): escaped, Zeilenumbruch als <br />');
    T::same("<p class=\"cms-plain\">a\u{FFFD}</p>", ContentRenderer::fallbackHtml("a\xC3"), 'fallbackHtml(): ungültiges UTF-8 wird durch U+FFFD ersetzt');
});

T::run('B Pathologie: Grenzen vor und nach dem Parsen, Speicherspitze < 64 MiB und ≤ Schätzung (eigener Prozess je Lauf)', static function (): void {
    $command = escapeshellarg(PHP_BINARY) . ' -d memory_limit=1536M ' . escapeshellarg(__FILE__) . ' --pathology';
    $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    T::check(is_resource($process), 'Kindprozess gestartet');
    if (!is_resource($process)) {
        return;
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    T::same(0, $exit, 'Kindprozess endet regulär', trim(substr($err, -300)));
    $results = [];
    foreach (explode("\n", trim($out)) as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && isset($row['name'])) {
            $results[(string) $row['name']] = $row;
        }
    }
    $cases = crPathologyCases();
    T::same(array_keys($cases), array_keys($results), 'jeder Fall hat ein Ergebnis');
    $ceiling = 64 * 1048576;
    $wrong = [];
    $under = [];
    $info = [];
    foreach ($cases as $name => [$make, $expected]) {
        $row = $results[$name] ?? null;
        if ($row === null) {
            continue;
        }
        $peak = (int) $row['peak'];
        $ok = (bool) $row['ok'];
        $verdict = !$ok ? ((int) $row['prechecked'] === 0 ? 'pre' : 'post') : 'render';
        $info[] = sprintf('%s %s %.1f MB %.0f ms', $name, $verdict, $peak / 1048576, (float) $row['ms']);
        $good = match ($expected) {
            'pre', 'post' => $verdict === $expected,
            'render' => $verdict === 'render' && $peak < $ceiling,
            default => $verdict !== 'render' || $peak < $ceiling,
        };
        if (!$good || ($ok && $peak >= $ceiling)) {
            $wrong[] = $name . ' (erwartet ' . $expected . ', ist ' . $verdict . ', ' . round($peak / 1048576, 1) . ' MB)';
        }
        // Nur geparste Fälle: die Schätzung muss die Spitze decken, sonst endet ein knapper headroom im Fatal Error statt im Ausweichpfad.
        if ($verdict !== 'pre' && $peak > crNeed($make())) {
            $under[] = sprintf('%s (Spitze %.1f MiB, Schätzung %.1f MiB)', $name, $peak / 1048576, crNeed($make()) / 1048576);
        }
    }
    T::same([], $wrong, count($cases) . ' Fälle: vor dem Parsen abgelehnt, nach dem Parsen abgelehnt oder gerendert mit Spitze < 64 MiB wie erwartet');
    T::same([], $under, 'jeder geparste Fall: Spitze ≤ memoryNeed() (Begrenzer laut Vertrag §4.7 Nr. 1 mit \\, www. und @)');
    T::info('Pathologie (Zeit nur als Hinweis): ' . implode('; ', $info));
});

T::run('B Grenzen: analyze() und plainText() mit denselben Grenzen, Strukturgrenze, Speicherschätzung mit Fake-headroom', static function (): void {
    $over = str_repeat('[', ContentRenderer::MAX_DELIMITERS + 1);
    T::same(['ok' => false, 'stats' => ContentRenderer::emptyStats(), 'error' => 'render_failed'], crRenderer()->analyze($over), 'analyze(): Begrenzer über der Grenze → render_failed');
    T::same('', crRenderer()->plainText($over), 'plainText(): Begrenzer über der Grenze → „“');
    $tags = str_repeat('<b>', 20001);
    T::same([false, false], [crRenderer()->render($tags)['ok'], crRenderer()->analyze($tags)['ok']], 'Tag-Anfänge zählen als Begrenzer: 20.001 × <b> → render_failed (render und analyze)');
    T::same([true, true], [crRenderer()->render(str_repeat('<b>', 20000))['ok'], crRenderer()->render(str_repeat('a < b ', 30000))['ok']], '20.000 × <b> und „a < b“ × 30.000 (kein Tag-Anfang) werden gerendert');
    T::same([true], [crRenderer()->render(str_repeat('<!-- -->', 2000) . str_repeat('<?x?>', 2000) . str_repeat('</i>', 2000))['ok']], '<!, <? und </ zählen mit (unter der Grenze gerendert)');
    T::same([false], [crRenderer()->render(str_repeat('<!x', 10001) . str_repeat('<?x', 10000))['ok']], '<! und <? zählen als Tag-Anfänge (20.001 → abgelehnt)');

    // Nachbesserung N1: `\` (Escape-Parser), `www.` (Autolink-Präfix, ohne Groß-/Kleinschreibung) und `@` (E-Mail-Autolink) zählen als Begrenzer.
    $backslashes = str_repeat('\\', ContentRenderer::MAX_DELIMITERS + 1);
    T::same([false, false, ''], [crRenderer()->render($backslashes)['ok'], crRenderer()->analyze($backslashes)['ok'], crRenderer()->plainText($backslashes)], '20.001 × \\ → render_failed (render, analyze, plainText)');
    T::same(true, crRenderer()->render(str_repeat('\\', ContentRenderer::MAX_DELIMITERS))['ok'], 'genau MAX_DELIMITERS × \\ wird gerendert');
    T::same([false, false, false], [crRenderer()->render(str_repeat('\\\\', 100000))['ok'], crRenderer()->analyze(str_repeat('\\\\', 100000))['ok'], crRenderer()->plainText(str_repeat('\\\\', 100000)) !== ''], '\\\\ × 100.000 (200.000 B, post_max 200.000) → render_failed vor dem Parsen');
    T::same([false, false, false], [crRenderer()->render(str_repeat('wWw.', 20001))['ok'], crRenderer()->render(str_repeat('a@b.c,', 20001))['ok'], crRenderer()->render(str_repeat('\\!', 20001))['ok']], '20.001 × www. (beliebige Schreibung), 20.001 E-Mail-Adressen und 20.001 × \\! → render_failed');
    T::same([true, true], [crRenderer()->render(str_repeat('www.', 20000))['ok'], crRenderer()->render(str_repeat('a@b.c,', 2000))['ok']], 'www. × 20.000 und 2.000 E-Mail-Adressen werden gerendert');

    // Strukturgrenzen nach dem Parsen
    $cells = "|a|b|\n|-|-|\n" . str_repeat("|x|y|\n", 2600);
    T::same(false, crRenderer()->render($cells)['ok'], 'mehr als MAX_TABLE_CELLS Tabellenzellen → render_failed');
    T::same([false, ''], [crRenderer()->analyze($cells)['ok'], crRenderer()->plainText($cells)], 'analyze() und plainText() mit derselben Strukturgrenze');
    T::same(true, crRenderer()->render("|a|b|\n|-|-|\n" . str_repeat("|x|y|\n", 2400))['ok'], '4.802 Zellen werden gerendert');
    $nodes = str_repeat("x\n", 19000);
    T::same(false, crRenderer()->render($nodes)['ok'], 'mehr als MAX_NODES Knoten (19.000 Zeilen = 38.000 Knoten) → render_failed');
    T::same(true, crRenderer()->render(str_repeat("x\n", 12000))['ok'], '24.000 Knoten werden gerendert');
    $calls = 0;
    $spy = crRenderer(headroom: static function () use (&$calls): ?int {
        $calls++;

        return null;
    });
    $spy->render($cells);
    T::same(1, $calls, 'Strukturgrenze greift nach der Vorprüfung (Speicherschätzung gefragt)');
    $calls = 0;
    $spy->render($over);
    T::same(0, $calls, 'Begrenzer: abgelehnt vor der Speicherschätzung, also vor dem Parsen');
    $calls = 0;
    $spy->render(str_repeat('\\\\', 100000));
    T::same(0, $calls, '\\\\ × 100.000: abgelehnt vor der Speicherschätzung, also vor dem Parsen');

    // Speicherschätzung
    T::same([4194304, 4533760, ContentRenderer::MEMORY_CEILING], [ContentRenderer::memoryNeed(0, 0, 0, 0), ContentRenderer::memoryNeed(1000, 10, 5, 2), ContentRenderer::memoryNeed(800000, 20000, 20000, 0)], 'memoryNeed(): 4 MiB + 256·Bytes + 5120·Zeilen + 4608·(Begrenzer + |), höchstens 64 MiB');
    $text = str_repeat('Wort ', 4000) . str_repeat('|', 10) . '<b>';
    $need = ContentRenderer::memoryNeed(strlen($text), 1, 1, 10);
    $seen = [];
    $fake = static function (int $free) use (&$seen): \Closure {
        return static function () use ($free, &$seen): ?int {
            $seen[] = $free;

            return $free;
        };
    };
    T::same(false, crRenderer(headroom: $fake($need - 1))->render($text)['ok'], 'freier Speicher knapp unter memoryNeed() → render_failed');
    T::same(true, crRenderer(headroom: $fake($need))->render($text)['ok'], 'freier Speicher = memoryNeed() → gerendert (Tag-Anfänge und | zählen in der Schätzung)');
    T::same([false, ''], [crRenderer(headroom: $fake(1048576))->analyze('kurz')['ok'], crRenderer(headroom: $fake(1048576))->plainText('kurz')], 'analyze() und plainText() mit derselben Speicherschätzung');

    // Nachbesserung N1: \, www. und @ gehen mit 4,5 KiB je Treffer in die Schätzung ein.
    $mixed = str_repeat('Wort ', 2000) . '\\\\ www.x.de WWW.y.de a@b.de \\*';
    T::same(ContentRenderer::memoryNeed(strlen($mixed), 1, 7, 0), crNeed($mixed), 'crNeed(): 3 × \\, 2 × www., 1 × @, 1 × * = 7 Begrenzer');
    T::same([false, true], [crRenderer(headroom: $fake(crNeed($mixed) - 1))->render($mixed)['ok'], crRenderer(headroom: $fake(crNeed($mixed)))->render($mixed)['ok']], 'Speicherschätzung zählt \\, www. und @: knapp darunter → render_failed, gleich → gerendert');
    // \\ × 10.000 (20.000 × \ = MAX_DELIMITERS): ohne \ als Begrenzer schätzte die Vorprüfung 8,9 MiB, die Spitze liegt bei etwa 22 MiB.
    $pairs = str_repeat('\\\\', 10000);
    $between = 16 * 1048576;
    T::same([false, false, ''], [crRenderer(headroom: $fake($between))->render($pairs)['ok'], crRenderer(headroom: $fake($between))->analyze($pairs)['ok'], crRenderer(headroom: $fake($between))->plainText($pairs)], '\\\\ × 10.000 bei 16 MiB frei (zwischen alter Schätzung und Spitze) → Ausweichpfad statt Fatal Error');
    T::check(crNeed($pairs) > $between, 'Schätzung für \\\\ × 10.000 liegt über 16 MiB (' . round(crNeed($pairs) / 1048576, 1) . ' MiB)');
    T::same(true, crRenderer(headroom: static fn (): ?int => null)->render($text)['ok'], 'headroom null = unbegrenzt');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// D Zählung
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('D Zählung: Links, Bilder, Anhänge, Hosts, Erwähnungen, Zitate; analyze() = render()[stats]', static function (): void {
    $corpus = [
        'inline-referenz-spitz' => ["[a](https://x.example/1) [b][r] <https://y.example/2>\n\n[r]: https://z.example/3", crStats(['links' => 3, 'external_links' => 3, 'hosts' => ['x.example', 'y.example', 'z.example']])],
        'gfm-autolinks' => ['https://x.example www.y.example a@b.example', crStats(['links' => 3, 'external_links' => 3, 'hosts' => ['b.example', 'www.y.example', 'x.example']])],
        'mailto-relativ-anker' => ['[m](mailto:Info@Forum.Example) [r](/pfad) [h](#a) [i](https://forum.example/x)', crStats(['links' => 4, 'external_links' => 1])],
        'mailto-fremd' => ['[m](mailto:x@Evil.Example?subject=hi)', crStats(['links' => 1, 'external_links' => 1, 'hosts' => ['evil.example']])],
        'code-ohne' => ["`https://x.example` und\n\n```\n[a](https://y.example) ![b](attachment:5)\n```", crStats([])],
        'roh-html-ohne' => ['<a href="https://x.example">x</a>', crStats([])],
        'bilder' => ["![a](https://x.example/a.png) ![b][r] ![c](attachment:5) ![d](attachment:9 \"t\")\n\n[r]: //y.example/b.png", crStats(['images' => 4, 'external_images' => 2, 'attachments' => [5, 9], 'hosts' => ['x.example', 'y.example']])],
        'anhang-in-link' => ['[Datei](attachment:12) [![x](attachment:3)](attachment:7) ![z](attachment:3)', crStats(['images' => 2, 'attachments' => [3, 7, 12]])],
        'anhang-ungueltig' => ['[a](attachment:0) ![b](attachment:4294967296) ![c](attachment:4294967295)', crStats(['links' => 1, 'images' => 2, 'external_images' => 1, 'attachments' => [4294967295]])],
        'falscher-marker-zaehlt' => ["> [quote=post:x]\n> [a](https://x.example)", crStats(['links' => 1, 'external_links' => 1, 'hosts' => ['x.example']])],
        'zitat-block-zaehlt' => ["> [quote=post:7]\n> [a](https://x.example) @anna", crStats(['links' => 1, 'external_links' => 1, 'hosts' => ['x.example'], 'mentions' => 1, 'quotes' => [7]])],
        'spoiler-zaehlt' => ['>! [a](https://x.example) ![b](https://y.example/b.png)', crStats(['links' => 1, 'external_links' => 1, 'images' => 1, 'external_images' => 1, 'hosts' => ['x.example', 'y.example']])],
        'erwaehnungen' => ["@anna sagt hallo @bob_1, mail@host.de, `@code`, [@link](https://x.example), ![@bild](attachment:5) und\n\n```\n@block\n```\n\n> @zitat\n\nx@y @a", crStats(['links' => 2, 'external_links' => 2, 'images' => 1, 'attachments' => [5], 'hosts' => ['host.de', 'x.example'], 'mentions' => 3])],
        'hosts-normalisiert' => ['[a](https://evil%2Ecom/x) [b](https://%65vil.com/y) [c](HTTPS://Example.ORG) [d](https://xn--bcher-kva.de) [e](https://b%C3%BCcher.de)', crStats(['links' => 5, 'external_links' => 5, 'hosts' => ['bücher.de', 'evil.com', 'example.org', 'xn--bcher-kva.de']])],
        'www-site-fremd' => ['[a](https://www.forum.example/x) [b](https://forum.example/y) [c](https://forum.example:8443/z)', crStats(['links' => 3, 'external_links' => 1, 'hosts' => ['www.forum.example']])],
        'protokollrelativ' => ['[a](//evil.example/x) ![b](//cdn.example/y.png)', crStats(['links' => 1, 'external_links' => 1, 'images' => 1, 'external_images' => 1, 'hosts' => ['cdn.example', 'evil.example']])],
        'leerer-host-failclosed' => ['[a](https://?q)', crStats(['links' => 1, 'external_links' => 1])],
        // Nachbesserung N1: nach `http:`/`https:` gelten `/` und `\` beliebig oft (auch keins) als `//` (WHATWG-Sonderschema)
        'schema-ohne-schraegstriche' => ['[a](https:-login.com/konto) <https:-login.com/k> [b](https:%65vil.com/) [c](HTTPS:Evil.COM/x) [d](https:/evil.org/y) [e](https:///pfad) ![i](https:img.example/a.png)', crStats(['links' => 6, 'external_links' => 6, 'images' => 1, 'external_images' => 1, 'hosts' => ['-login.com', 'evil.com', 'evil.org', 'img.example', 'pfad']])],
        'andere-schemata' => ['[a](ftp://x.example) [b](tel:123) [c](javascript:alert(1))', crStats(['links' => 3])],
        'zitate-reihenfolge' => ["> [quote=post:9]\n> a\n\n> [quote=post:3]\n> b\n\n> [quote=post:9]\n> c", crStats(['quotes' => [9, 3]])],
    ];
    $renderer = crRenderer();
    foreach ($corpus as $name => [$markdown, $expected]) {
        $rendered = $renderer->render($markdown, ['post_id' => 10, 'uploader_id' => 7]);
        T::same($expected, $rendered['stats'], 'Zählung ' . $name);
        T::same(['ok' => true, 'stats' => $rendered['stats']], $renderer->analyze($markdown), 'analyze() = render()[stats]: ' . $name);
    }
    T::same(ContentRenderer::emptyStats(), ['links' => 0, 'external_links' => 0, 'images' => 0, 'external_images' => 0, 'attachments' => [], 'hosts' => [], 'mentions' => 0, 'quotes' => []], 'emptyStats(): alle Felder');
});

T::run('D Hosts und Zitatgrenze: normalizeHost(), www.<Site> fremd, 25 Zitat-Blöcke ⇒ 20 mit Kennzeichen, Überlauf ohne Quelltext', static function (): void {
    T::same(['evil.com', 'evil.com', 'example.org', 'xn--bcher-kva.de', 'www.forum.example', 'bücher.de'], array_map([ContentRenderer::class, 'normalizeHost'], ['evil%2Ecom', '%65vil.com', 'Example.ORG', 'xn--bcher-kva.de', 'WWW.Forum.Example', 'b%C3%BCcher.de']), 'normalizeHost(): Prozent-Kodierung aufgelöst, klein, Punycode und www. bleiben');
    $html = (string) crHtml('[a](https://www.forum.example/x) [b](https://forum.example/y)');
    T::check(str_contains($html, '<a href="https://www.forum.example/x" rel="nofollow noreferrer noopener ugc" target="_blank">a</a>'), 'www.<Site> gilt als fremd und erhält ugc (wie der Purifier)', $html);
    T::check(str_contains($html, '<a href="https://forum.example/y">b</a>'), 'der exakte Site-Host ist intern', $html);
    T::same(['links' => 1, 'external_links' => 0], array_intersect_key((new ContentRenderer(siteHost: 'WWW.Forum.Example'))->analyze('[a](https://www.forum.example/x)')['stats'], ['links' => 0, 'external_links' => 0]), 'siteHost aus dem Konstruktor wird über normalizeHost() verglichen');

    // Nachbesserung N1: http(s) ohne „//“ nach dem Schema. Der Purifier ergänzt einen fehlenden Host durch URI.Host und hängt den Pfad
    // direkt an („https:-login.com“ → „https://forum.example-login.com“). Nach R4 gilt: gezählter Host = Host der Ausgabe oder Site-Host.
    foreach (['[a](https:-login.com/konto)', '<https:-login.com/konto>', '[a](https:%65vil.com/)', '[a](HTTPS:evil.com/x)', '[a](https:/evil.com/x)', '[a](https:\\\\evil.com/x)', '[a](https:///evil.com/x)', '![a](https:evil.com/a.png)', '[a](http:.evil.com/)'] as $markdown) {
        $rendered = crRenderer()->render($markdown);
        $html = (string) $rendered['html'];
        T::check(preg_match('~(?:href|src)="https?://forum\.example[^/?#"]~i', $html) !== 1, 'kein Site-Host mit angehängtem Text: ' . $markdown, $html);
        $host = preg_match('~href="https?://([^/?#"]*)~i', $html, $m) === 1 ? ContentRenderer::normalizeHost($m[1]) : 'forum.example';
        T::check($host === 'forum.example' || in_array($host, $rendered['stats']['hosts'], true), 'Host der Ausgabe ist der Site-Host oder steht in Stats.hosts: ' . $markdown, $html . ' | ' . json_encode($rendered['stats']['hosts']));
    }
    T::same("<p><a href=\"https://evil.com/x\" rel=\"nofollow noreferrer noopener ugc\" target=\"_blank\">a</a></p>\n", crHtml('[a](https:evil.com/x)'), 'https:evil.com/x wird https://evil.com/x (fremd, ugc)');
    T::same(['https://evil.com/x', 'https://x.example/y', 'mailto:a@b.de', '/pfad'], array_map(static fn (string $url): string => (string) (new \ReflectionMethod(ContentRenderer::class, 'absolute'))->invoke(null, $url), ['https:\\/\\evil.com/x', '//x.example/y', 'mailto:a@b.de', '/pfad']), 'absolute(): Schrägstriche und Backslashes nach https: werden „//“, //host wird https://host, andere Ziele bleiben');

    $markdown = '';
    for ($i = 1; $i <= 25; $i++) {
        $markdown .= "> [quote=post:{$i}]\n> Geheim {$i}\n\n";
    }
    $rendered = crRenderer()->render($markdown);
    T::same(range(1, 20), $rendered['stats']['quotes'], 'Stats.quotes: höchstens MAX_QUOTES = 20 IDs');
    T::same(20, substr_count((string) $rendered['html'], 'data-quote-post='), '25 Zitat-Blöcke ⇒ 20 mit data-quote-post');
    T::same(5, substr_count((string) $rendered['html'], '<blockquote class="cms-quote cms-quote--hidden"><p>[…]</p></blockquote>'), 'Überlauf: fünf Blöcke als [… ] ohne ID (Klasse cms-quote--hidden)');
    T::same([false, false, true], [str_contains((string) $rendered['html'], 'Geheim 21'), str_contains((string) $rendered['html'], 'Geheim 25'), str_contains((string) $rendered['html'], 'Geheim 20')], 'Überlauf ohne den Text der Quelle');
    T::same(range(1, 20), crRenderer()->analyze($markdown)['stats']['quotes'], 'analyze() mit derselben Zitatgrenze');
    $same = str_repeat("> [quote=post:4]\n> x\n\n", 22);
    T::same([[4], 20], [crRenderer()->analyze($same)['stats']['quotes'], substr_count((string) crHtml($same), 'data-quote-post="4"')], 'die Grenze zählt Blöcke: 22 Zitate derselben Quelle ⇒ 20 Blöcke mit Kennzeichen');
});

T::run('D Klartext und Ausgaberegel: plainText() ohne und mit Zitaten, QuoteService::markerId() und presentable()', static function (): void {
    $markdown = "# Titel\n\n> [quote=post:5]\n> zitiert\n\n**fett** `code` [link](https://x.example) ![alt](attachment:5)\n\n```\nblock\n```\n\n<b>roh</b>\n\n>! spoiler";
    T::same('Titel fett code link alt block <b>roh</b> spoiler', crRenderer()->plainText($markdown), 'plainText(): Text, Code, Codeblock, sichtbares Roh-HTML, Spoiler; ohne Zitat-Block');
    T::same('Titel zitiert fett code link alt block <b>roh</b> spoiler', crRenderer()->plainText($markdown, true), 'plainText(…, true): mit Zitat-Block, ohne Markerzeile');
    T::same('Zeile eins Zeile zwei', crRenderer()->plainText("Zeile   eins\nZeile\tzwei\n\n\n"), 'plainText(): Leerraum zusammengefasst');
    $overflow = str_repeat("> [quote=post:1]\n> q\n\n", 21) . 'Ende';
    T::same('Ende', crRenderer()->plainText($overflow), 'plainText(): auch Zitate über der Grenze nur mit $withQuotes');
    T::same(trim(str_repeat('q ', 21)) . ' Ende', crRenderer()->plainText($overflow, true), 'plainText(…, true): Zitate über der Grenze ohne Markerzeile');
    T::same('', crRenderer(available: static fn (): bool => false)->plainText('x'), 'plainText(): Fehler → „“');

    T::same([5, 5, null, null, null, null, 1234567890123456789], array_map([QuoteService::class, 'markerId'], ['> [quote=post:5]', "> [quote=post:5] \t", '> \\[quote=post:5]', '>[quote=post:5]', '  > [quote=post:5]', '> [quote=post:0]', '> [quote=post:1234567890123456789]']), 'markerId(): einzige Marker-Regel (Quellzeile)');
    T::same([9, 3], QuoteService::extractRefs("> [quote=post:9]\n> a\n> [quote=post:3]\n> [quote=post:9]"), 'extractRefs() nutzt dieselbe Regel');

    $visible = static fn (int $id): bool|string => match ($id) {
        1 => true,
        2 => QuoteService::REMOVED,
        default => false,
    };
    $item = ['id' => 40, 'view' => 'full', 'body' => "> [quote=post:3]\n> Geheimtext\n\nAntwort\n\n> [quote=post:1]\n> Freitext", 'body_html' => '<p>Geheimtext</p>'];
    $out = QuoteService::presentable($item, $visible);
    T::check(!array_key_exists('body_html', $out), 'presentable(): body_html entfernt');
    T::same("> [quote=post:3]\n> " . QuoteService::HIDDEN_NOTICE . "\n\nAntwort\n\n> [quote=post:1]\n> Freitext", $out['body'] ?? null, 'presentable(): body neutralisiert (nicht sichtbare Quelle ersetzt, sichtbare bleibt)');
    T::check(!str_contains((string) json_encode($out), 'Geheimtext'), 'presentable(): der Geheimtext steht nirgends im Eintrag');
    T::same(['id' => 40, 'view' => 'full', 'body' => QuoteService::neutralize($item['body'], $visible)], $out, 'presentable(): übrige Felder unverändert');
    T::same(['id' => 41, 'body' => "> [quote=post:2]\n> " . QuoteService::REMOVED_NOTICE], QuoteService::presentable(['id' => 41, 'body' => "> [quote=post:2]\n> weg", 'body_html' => '<p>weg</p>'], $visible), 'presentable(): Zeile ohne view gilt als voll; ausgeblendete Quelle → Platzhalter');
    $placeholder = ['view' => 'placeholder', 'id' => 42, 'status' => 'hidden'];
    T::same($placeholder, QuoteService::presentable($placeholder, $visible), 'presentable(): Platzhalter bleiben, wie sie sind');
    T::same($placeholder, QuoteService::presentable($placeholder + ['body' => 'roh', 'body_html' => '<p>roh</p>'], $visible), 'presentable(): Platzhalter tragen keinen Text (fail-closed)');
    T::same(['id' => 43, 'body' => null], QuoteService::presentable(['id' => 43, 'body' => null, 'body_html' => null], $visible), 'presentable(): body null bleibt null, body_html entfällt');
    $failClosed = QuoteService::presentable($item, static fn (int $id): bool => false);
    T::check(!str_contains((string) json_encode($failClosed), 'Geheimtext') && !str_contains((string) json_encode($failClosed), 'Freitext'), 'presentable(…, fn () => false): alle Zitate verborgen (fail-closed wie ohne RenderService)');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// E Purifier-Profil
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Schnappschuss der unveränderten Profile (SHA-256 über elements und attributes, Stand vor PR #2b). */
const CR_PROFILE_SNAPSHOT = [
    'default' => '9ed4792a9299066b3186bc4d50f1a6a3d04cc3123422016e9a4e63b20b372527',
    'hub' => '3111faaa3c65b5ae74372f0e5a47bb5760d4b5be500478e7be523b11d824d6d4',
    'table' => 'cde096b1194a418b8966eb63117868541c18611b8cf52c55333b5d362b7a03eb',
    'table_cell' => 'b5b86e794ceb505c61ecd15b436d17573e6ee667e1dc412159a8e216fd952910',
    'strict' => '6e0bc41304dcbdac3c03d1963292e8900bf0b5d3db1be7f0b7c63326020a6661',
    'minimal' => '6c5029b833f7f2b71f3c5218302b92baec0b309aee5c7db1fb2631356ab9e17f',
];

T::run('E Purifier-Profil community: Elemente, Schemata, Klassen, fremde Bilder, //host, prozessweite Schema-Registry', static function (): void {
    $reflection = new \ReflectionClass(PurifierService::class);
    $profiles = $reflection->getConstant('PROFILES');
    $profile = $profiles['community'] ?? null;
    T::check(is_array($profile), 'PROFILES[community] vorhanden');
    T::check(in_array('community', (array) $reflection->getConstant('UGC_PROFILES'), true), 'UGC_PROFILES enthält community');
    T::check((int) $reflection->getConstant('HTML_DEFINITION_REVISION') >= 2026112001, 'HTML_DEFINITION_REVISION ≥ 2026112001');
    T::same(['http', 'https', 'mailto'], $profile['schemes'] ?? null, 'Schemata: http, https, mailto');
    T::same(false, $profile['external_resources'] ?? null, 'external_resources = false');
    $classes = (array) ($profile['classes'] ?? []);
    $required = array_merge(array_map(static fn (string $l): string => 'language-' . $l, array_values(array_unique(ContentRenderer::LANGUAGES))), ['text-start', 'text-center', 'text-end', 'cms-task', 'cms-task--done', 'cms-quote', 'cms-quote--hidden', 'cms-spoiler']);
    T::same([], array_values(array_diff($required, $classes)), 'classes ⊇ language-<LANGUAGES>, Ausrichtung, Aufgaben, Zitate (inkl. Überlauf), Spoiler');
    T::same([], array_values(array_diff($classes, $required)), 'classes enthält keine weitere Klasse');
    $snapshot = [];
    foreach (array_keys(CR_PROFILE_SNAPSHOT) as $name) {
        $snapshot[$name] = hash('sha256', ($profiles[$name]['elements'] ?? '') . '|' . ($profiles[$name]['attributes'] ?? '') . '|' . json_encode(array_diff_key($profiles[$name] ?? [], ['elements' => 0, 'attributes' => 0])));
    }
    T::same(CR_PROFILE_SNAPSHOT, $snapshot, 'übrige Profile unverändert (Schnappschuss elements/attributes, keine Zusatzschlüssel)');

    $purifier = PurifierService::getInstance();
    T::check($purifier->isAvailable(), 'HTMLPurifier verfügbar');
    // Prozessweite Schema-Registry: zuerst lädt strict das Schema tel, danach darf community es trotzdem nicht durchlassen.
    T::same('<a href="tel:123">t</a>', $purifier->purify('<a href="tel:123">t</a>', 'strict'), 'strict lässt tel: durch (unverändert)');
    T::same('<a>t</a>', $purifier->purify('<a href="tel:123">t</a>', 'community'), 'community entfernt tel:, auch nachdem strict im selben Prozess tel: geladen hat');
    T::same('<a>t</a>', $purifier->purify('<a href="ftp://x.example/a">t</a>', 'community'), 'community entfernt ftp:');
    $foreign = $purifier->purify('<p>a<img src="https://evil.example/x.png" alt="x"></p>', 'community');
    T::check(!str_contains($foreign, '<img') && !str_contains($foreign, 'evil.example'), 'fremdes <img> entfernt (URI.DisableExternalResources)', $foreign);
    T::same('<img src="/forum/anhang/1" alt="x" />', $purifier->purify('<img src="/forum/anhang/1" alt="x">', 'community'), 'relatives <img> bleibt');
    T::same('<img src="https://forum.example/a.png" alt="x" />', $purifier->purify('<img src="https://forum.example/a.png" alt="x">', 'community'), 'eigenes <img> (Site-Host) bleibt');
    T::same('<span class="cms-task">x</span>', $purifier->purify('<span class="evil cms-task" style="color:red" onclick="x()">x</span>', 'community'), 'fremde Klasse, style und on* entfallen');
    T::same('Titel', $purifier->purify('<h1>Titel</h1>', 'community'), 'h1 wird Text');
    T::same('<table><tbody><tr><td></td><td>x</td></tr></tbody></table>', $purifier->purify('<table><tbody><tr><td></td><td>x</td></tr></tbody></table>', 'community'), 'leere Tabellenzelle bleibt');
    T::same('<blockquote class="cms-quote" data-quote-post="5"><p>x</p></blockquote>', $purifier->purify('<blockquote class="cms-quote" data-quote-post="5"><p>x</p></blockquote>', 'community'), 'data-quote-post mit Zahl bleibt');
    T::same(['<blockquote><p>x</p></blockquote>', '<blockquote><p>x</p></blockquote>', '<blockquote><p>x</p></blockquote>'], [$purifier->purify('<blockquote data-quote-post="x"><p>x</p></blockquote>', 'community'), $purifier->purify('<blockquote data-quote-post="-1"><p>x</p></blockquote>', 'community'), $purifier->purify('<blockquote data-quote-post="5"><p>x</p></blockquote>', 'strict')], 'data-quote-post nur als positive Zahl und nur im Profil community');
    T::same('<blockquote class="cms-quote cms-quote--hidden"><p>[…]</p></blockquote>', $purifier->purify('<blockquote class="cms-quote cms-quote--hidden"><p>[…]</p></blockquote>', 'community'), 'Überlauf-Block der Zitatgrenze bleibt (Klasse cms-quote--hidden)');
    T::same('<details class="cms-spoiler"><summary>Spoiler</summary><p>x</p></details>', $purifier->purify('<details class="cms-spoiler" open><summary>Spoiler</summary><p>x</p></details>', 'community'), 'Spoiler: details/summary, open entfällt');
    T::same('<a href="https://evil.example/x" rel="nofollow noreferrer noopener ugc" target="_blank">x</a>', $purifier->purify('<a href="https://evil.example/x">x</a>', 'community'), 'community: externer Link mit nofollow ugc');
    T::same('<a href="//evil.example/x" rel="nofollow noreferrer noopener ugc" target="_blank">x</a>', $purifier->purify('<a href="//evil.example/x">x</a>', 'community'), 'community: //host erhält ugc');
    T::same('<a href="//evil.example/x" rel="nofollow noreferrer noopener ugc" target="_blank">x</a>', $purifier->purify('<a href="//evil.example/x">x</a>', 'strict'), 'strict: //host erhält ugc (Korrektur in isForeignUrl, gewollt)');
    T::same('<a href="//evil.example/x" target="_blank" rel="noreferrer noopener">x</a>', $purifier->purify('<a href="//evil.example/x">x</a>', 'default'), 'default: //host bleibt follow (redaktionell)');
    T::same('<a href="https://forum.example/x">x</a>', $purifier->purify('<a href="https://forum.example/x">x</a>', 'community'), 'community: eigener Host ohne ugc');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// F Version
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Fingerabdrücke der Darstellung je VERSION (render §6.1 F). Ändert sich Konfiguration, eigener Renderer, Profil `community`,
 * `HTML_DEFINITION_REVISION`, Bündel oder Marker-Regel, scheitert der Test, bis VERSION erhöht und der neue Wert eingetragen ist.
 * Ausnahme bis zur Auslieferung von 4.2.0: Stand 1 ist noch nirgends gespeichert, sein Wert wird ersetzt statt VERSION zu erhöhen
 * (Nachbesserung N1: `\`, `@`, `www.` als Begrenzer, Schema ohne `//` in R4).
 */
const CR_FINGERPRINTS = [
    1 => '13774f9d02b4d9c24b028ac8cd4d5b74a3558e6aefd097ef9ac7e7527d03895d',
];

/**
 * Fingerabdruck aus den Dateien (nicht über Reflection: eine Mutante lädt eine Kopie, die Dateien bleiben unverändert, so prüft F
 * nur echte Änderungen im Repository): Quelltext von ContentRenderer.php ohne Kommentare und Leerraum (enthält CONFIG, LANGUAGES,
 * IMAGE_MIME, SPOILER_SUMMARY), Profil community und HTML_DEFINITION_REVISION aus PurifierService.php, MARKER aus QuoteService.php,
 * SHA-256 des Bündels (LF).
 */
function crFingerprint(): array
{
    $code = '';
    foreach (token_get_all((string) file_get_contents(ABSPATH . 'core/Services/Community/Support/ContentRenderer.php')) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    $purifier = (string) file_get_contents(ABSPATH . 'core/Services/PurifierService.php');
    preg_match("/\n        'community' => \\[\n(.*?)\n        \\],\n/s", $purifier, $profile);
    preg_match('/HTML_DEFINITION_REVISION = (\d+);/', $purifier, $revision);
    preg_match("/private const MARKER = '([^']+)';/", (string) file_get_contents(ABSPATH . 'core/Services/Community/Service/QuoteService.php'), $marker);
    $parts = [
        'renderer' => hash('sha256', $code),
        'profile' => hash('sha256', (string) preg_replace('/\s+/', '', $profile[1] ?? '')),
        'revision' => $revision[1] ?? '',
        'marker' => $marker[1] ?? '',
        'bundle' => hash('sha256', str_replace(["\r\n", "\r"], "\n", (string) file_get_contents(ABSPATH . 'assets/commonmark/commonmark.php'))),
    ];

    return ['parts' => $parts, 'hash' => hash('sha256', (string) json_encode($parts))];
}

T::run('F Version: Fingerabdruck der Darstellung, VERSION und MIN_SERVE_VERSION', static function (): void {
    $fingerprint = crFingerprint();
    T::check(!in_array('', $fingerprint['parts'], true), 'alle Teile des Fingerabdrucks gefunden', json_encode($fingerprint['parts']));
    T::check(isset(CR_FINGERPRINTS[ContentRenderer::VERSION]), 'CR_FINGERPRINTS enthält VERSION ' . ContentRenderer::VERSION);
    T::same(max(array_keys(CR_FINGERPRINTS)), ContentRenderer::VERSION, 'VERSION ist der höchste eingetragene Stand');
    T::same(CR_FINGERPRINTS[ContentRenderer::VERSION] ?? null, $fingerprint['hash'], 'Fingerabdruck = Eintrag für VERSION (Darstellung geändert ⇒ VERSION erhöhen und Wert eintragen)');
    T::check(ContentRenderer::MIN_SERVE_VERSION >= 1 && ContentRenderer::MIN_SERVE_VERSION <= ContentRenderer::VERSION, '1 ≤ MIN_SERVE_VERSION ≤ VERSION');
    T::check(ContentRenderer::VERSION <= 65535, 'VERSION ≤ 65535 (Spalte rendered_version SMALLINT UNSIGNED)');
    T::same(['ok' => true, 'version' => ContentRenderer::VERSION], array_intersect_key(crRenderer()->render('x'), ['ok' => 0, 'version' => 0]), 'render() liefert version = VERSION');
    T::same('community', ContentRenderer::PROFILE, 'PROFILE = community');
});

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// I Sprache
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

T::run('I Sprache: community.quote.* (DE = Konstanten von QuoteService, EN ≠ DE)', static function (): void {
    I18n::reset('de', 'de');
    try {
        T::same(QuoteService::HIDDEN_NOTICE, I18n::t('community.quote.hidden', [], 'de'), 'community.quote.hidden (DE) = QuoteService::HIDDEN_NOTICE');
        T::same(QuoteService::REMOVED_NOTICE, I18n::t('community.quote.removed', [], 'de'), 'community.quote.removed (DE) = QuoteService::REMOVED_NOTICE');
        foreach (['hidden', 'removed', 'source'] as $key) {
            $de = I18n::t('community.quote.' . $key, [], 'de');
            $en = I18n::t('community.quote.' . $key, [], 'en');
            T::check($de !== 'community.quote.' . $key && $en !== 'community.quote.' . $key && $de !== $en, 'community.quote.' . $key . ': DE und EN vorhanden und verschieden', $de . ' | ' . $en);
        }
        T::same('render_failed', ContentRenderer::instance()->render("\xFF")['error'] ?? null, 'Fehlercode render_failed');
        T::check(I18n::t('community.error.render_failed', [], 'de') !== 'community.error.render_failed' && I18n::t('community.error.render_failed', [], 'en') !== I18n::t('community.error.render_failed', [], 'de'), 'community.error.render_failed in DE und EN');
    } finally {
        I18n::reset();
    }
});

// Teildateien weiterer Besitzer (R: G in W2, T: part-services.php in W3; Vertrag §1 Nr. 6).
foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
