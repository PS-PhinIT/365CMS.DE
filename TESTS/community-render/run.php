<?php
/**
 * Community-Darstellung (PR #2b; Vertrag DESIGN-2b §4.7, §7.1, Entwurf render §6.1): Renderer-Kern `Support\ContentRenderer`,
 * Purifier-Profil `community`, CommonMark-Bündel, Zitatregel `QuoteService::markerId()` und Ausgaberegel `QuoteService::presentable()`.
 *
 * Abschnitte (Besitzer): A Bündel, B Darstellung R1 bis R10 mit „Pathologie“, D Zählung, E Purifier-Profil, F Version, I Sprache (V, W1);
 * G `RenderService` und die Mengen-API von `QuoteService` (R, W2: Nachrendern im Budget, Wächter `edit_count`, Zitate je Betrachter,
 * Lecktests S1/S2, Zitatgrenzen, Filter, Job, Vorschau) und H Dienste mit Renderer (T, W3, `part-services.php`).
 * C (XSS-Korpus) liegt in `TESTS/community-xss`.
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

use CMS\Services\Community\Repository\PostRepository;
use CMS\Services\Community\Service\AccessDecision;
use CMS\Services\Community\Service\CommunityAccess;
use CMS\Services\Community\Service\PostService;
use CMS\Services\Community\Service\QuoteService;
use CMS\Services\Community\Service\RenderService;
use CMS\Services\Community\Service\TopicService;
use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\I18n;
use CMS\Services\Community\Support\Settings;
use CMS\Services\Community\Support\Urls;
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

// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────
// G RenderService (SQLite-Double, echte CommunityAccess; Vertrag §4.3, §4.7, Entwurf render §6.1 G)
// ──────────────────────────────────────────────────────────────────────────────────────────────────────────────────────────

/** Rollenrechte der Testwelt G (`admin` nimmt die Klasse selbst aus). */
const CR_ROLE_CAPS = [
    'member' => ['community.view', 'community.participate'],
    'moderator' => ['community.view', 'community.participate', 'community.moderate'],
];

/** Uhr der Abschnitte G: 2026-11-15 12:00:00 UTC. */
function crNow(): int
{
    return gmmktime(12, 0, 0, 11, 15, 2026);
}

/**
 * Fake von `BatchJobService` (nur die Methoden, die `RenderService` braucht); protokolliert jeden Aufruf.
 */
final class CrJobs
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];
    /** @var array<int, array<string, mixed>> */
    public array $jobs = [];
    public bool $busy = false;
    public ?\Throwable $throw = null;
    private int $seq = 0;

    public function start(string $type, string $scope, int $total, array $state = [], ?int $userId = null): array
    {
        $this->calls[] = ['start', $type, $scope, $total, $state, $userId];
        if ($this->throw !== null) {
            throw $this->throw;
        }
        $active = $this->findActive($type, $scope);
        if ($active !== null) {
            return ['job' => $active, 'resumed' => true];
        }
        $id = ++$this->seq;
        $this->jobs[$id] = ['id' => $id, 'type' => $type, 'scope' => $scope, 'status' => 'pending', 'total' => $total, 'processed' => 0, 'failed' => 0, 'state' => $state, 'created_by' => $userId];

        return ['job' => $this->jobs[$id], 'resumed' => false];
    }

    public function step(int $jobId, callable $worker): array
    {
        $this->calls[] = ['step', $jobId];
        $job = $this->jobs[$jobId] ?? throw new \RuntimeException('Job fehlt');
        if ($this->busy) {
            return $job + ['busy' => true];
        }
        if (!in_array($job['status'], ['pending', 'running'], true)) {
            return $job;
        }
        $result = $worker($job + ['status' => 'running']);
        $job['processed'] += (int) ($result['processed'] ?? 0);
        $job['failed'] += (int) ($result['failed'] ?? 0);
        $job['state'] = $result['state'] ?? $job['state'];
        $job['status'] = !empty($result['done']) ? 'done' : 'running';

        return $this->jobs[$jobId] = $job;
    }

    public function cancel(int $jobId): bool
    {
        $this->calls[] = ['cancel', $jobId];
        if (!isset($this->jobs[$jobId]) || !in_array($this->jobs[$jobId]['status'], ['pending', 'running'], true)) {
            return false;
        }
        $this->jobs[$jobId]['status'] = 'cancelled';

        return true;
    }

    public function find(int $jobId): ?array
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function findActive(string $type, string $scope = ''): ?array
    {
        foreach (array_reverse($this->jobs, true) as $job) {
            if ($job['type'] === $type && $job['scope'] === $scope && in_array($job['status'], ['pending', 'running'], true)) {
                return $job;
            }
        }

        return null;
    }

    public function latest(string $type, string $scope = ''): ?array
    {
        $this->calls[] = ['latest', $type, $scope];
        foreach (array_reverse($this->jobs, true) as $job) {
            if ($job['type'] === $type && $job['scope'] === $scope) {
                return $job;
            }
        }

        return null;
    }

    public function toPublic(array $job): array
    {
        return ['id' => (int) $job['id'], 'type' => (string) $job['type'], 'status' => (string) $job['status'], 'total' => (int) $job['total'], 'processed' => (int) $job['processed'], 'failed' => (int) $job['failed'], 'busy' => !empty($job['busy'])];
    }
}

/**
 * Testwelt G: SQLite-Double, echte `CommunityAccess` (Rollen aus CR_ROLE_CAPS, Modul an, E-Mail bestätigt), Uhr, Rechte-Spion mit
 * Überschreibung, zählender Purifier (= Zahl der Rendervorgänge mit HTML), Filter- und Drossel-Spion, Fake-Jobs. Konten: `member`
 * (Leser), `author`, `moderator` (global), `banned` (Schreibsperre); Foren: offen und verborgen (`hidden`, nur für die Moderation).
 */
final class CrWorld
{
    public CommunityTestDatabase $db;
    public Settings $settings;
    public CommunityAccess $access;
    public RenderService $render;
    public CrJobs $jobs;
    public int $now;
    public int $clockReads = 0;
    /** @var list<array{user: ?int, action: string, context: array<string, mixed>}> */
    public array $canLog = [];
    /** @var (\Closure(?int, string, array<string, mixed>): ?AccessDecision)|null Rückgabe null = echte Entscheidung */
    public ?\Closure $rule = null;
    public int $purifies = 0;
    public bool $purifierMissing = false;
    /** @var list<array{html: string, post_id: int}> */
    public array $filterLog = [];
    /** @var (\Closure(string, int): mixed)|null */
    public ?\Closure $filter = null;
    /** @var list<array{0: string, 1: ?int}> */
    public array $throttleLog = [];
    /** @var array{allowed: bool, retry_after: int} */
    public array $throttle = ['allowed' => true, 'retry_after' => 0];
    /** @var array<int, array<string, mixed>> Anhangszeilen für den Renderer */
    public array $attachments = [];
    public int $member;
    public int $author;
    public int $moderator;
    public int $banned;
    public int $open;
    public int $secret;
    public int $topic;
    public int $secretTopic;

    /** @param array<string, mixed> $settings */
    public function __construct(array $settings = [])
    {
        $this->db = CommunityTestDatabase::create();
        $this->now = crNow();
        $this->settings = Settings::fromArray($settings + ['guests_can_read' => true, 'base_path' => 'forum']);
        $this->access = new CommunityAccess(
            $this->db,
            $this->settings,
            static fn (string $role, string $capability): bool => $role === 'admin' || in_array($capability, CR_ROLE_CAPS[$role] ?? [], true),
            static fn (): bool => true,
            fn (): int => $this->now,
            static fn (int $userId): bool => true
        );
        $this->jobs = new CrJobs();
        $renderer = new ContentRenderer(
            purify: function (string $html): ?string {
                $this->purifies++;
                if ($this->purifierMissing) {
                    return null;
                }
                $purifier = PurifierService::getInstance();

                return $purifier->isAvailable() ? $purifier->purify($html, ContentRenderer::PROFILE) : null;
            },
            attachments: fn (array $ids): array => array_intersect_key($this->attachments, array_flip($ids)),
        );
        $this->render = new RenderService(
            $this->db,
            $this->settings,
            function (): int {
                $this->clockReads++;

                return $this->now;
            },
            function (?int $userId, string $action, array $context): AccessDecision {
                $this->canLog[] = ['user' => $userId, 'action' => $action, 'context' => $context];
                $override = $this->rule !== null ? ($this->rule)($userId, $action, $context) : null;

                return $override ?? $this->access->can($userId, $action, $context);
            },
            $renderer,
            fn (): CrJobs => $this->jobs,
            function (string $html, int $postId): mixed {
                $this->filterLog[] = ['html' => $html, 'post_id' => $postId];

                return $this->filter !== null ? ($this->filter)($html, $postId) : $html;
            },
            function (string $action, ?int $userId): array {
                $this->throttleLog[] = [$action, $userId];

                return $this->throttle;
            }
        );
        $this->member = $this->db->user(['username' => 'leser']);
        $this->author = $this->db->user(['username' => 'autor']);
        $this->moderator = $this->db->user(['username' => 'moderation', 'role' => 'moderator']);
        $this->banned = $this->db->user(['username' => 'gesperrt']);
        $this->db->ban(['user' => $this->banned, 'scope' => 'write']);
        $this->open = $this->db->forum(['title' => 'Offen']);
        $this->secret = $this->db->forum(['title' => 'Verborgen', 'status' => 'hidden']);
        $this->topic = $this->db->topic(['forum' => $this->open, 'user' => $this->author]);
        $this->secretTopic = $this->db->topic(['forum' => $this->secret, 'user' => $this->author]);
    }

    /**
     * Beitrag (Standard: veröffentlicht, im offenen Thema, vom Autor). `html` und `version` setzen den Cache.
     *
     * @param array<string, mixed> $o topic, user, status, html, version
     */
    public function post(string $body, array $o = []): int
    {
        $id = $this->db->post(['topic' => $o['topic'] ?? $this->topic, 'user' => $o['user'] ?? $this->author, 'status' => $o['status'] ?? 'published', 'body' => $body]);
        if (array_key_exists('html', $o)) {
            $this->db->execute('UPDATE cms_community_posts SET body_html = ?, rendered_version = ? WHERE id = ?', [$o['html'], $o['version'] ?? ContentRenderer::VERSION, $id]);
        }

        return $id;
    }

    /** @return array<string, mixed> Beitragszeile wie `PostRepository::find()` (mit `body` und `body_html`) */
    public function row(int $id): array
    {
        return (array) (new PostRepository($this->db))->find($id);
    }

    /** @return array<string, mixed>|null Zeile direkt aus der Tabelle */
    public function stored(int $id): ?array
    {
        return $this->db->row('community_posts', ['id' => $id]);
    }

    /**
     * Einträge des echten `PostService::listPage()` ohne Renderer (Stand #2a: volle Einträge mit `body` und `body_html`).
     *
     * @return list<array<string, mixed>>
     */
    public function listPage(?int $viewer, ?int $topic = null): array
    {
        $posts = new PostService($this->db, $this->settings, fn (): int => $this->now, fn (?int $u, string $a, array $c): AccessDecision => $this->access->can($u, $a, $c));
        $page = $posts->listPage($viewer, $topic ?? $this->topic, null, 100);
        T::same(true, $page['success'] ?? null, 'Vorbedingung: listPage liefert Einträge', json_encode($page));

        return $page['items'] ?? [];
    }

    /** Indizes der Anweisungen im Protokoll, die auf das Muster passen. @return list<int> */
    public function logged(string $pattern): array
    {
        return array_keys(array_filter($this->db->log, static fn (string $sql): bool => preg_match($pattern, $sql) === 1));
    }

    /** Alle gebundenen Parameter der passenden Anweisungen hintereinander. @return list<mixed> */
    public function loggedParams(string $pattern): array
    {
        $params = [];
        foreach ($this->logged($pattern) as $index) {
            array_push($params, ...$this->db->paramsAt($index));
        }

        return $params;
    }
}

/** Muster der Anweisungen: Nachrendern speichern, Beiträge nach ID lesen (`findMany`), Schreiben überhaupt. */
const CR_STORE_SQL = '/^UPDATE cms_community_posts SET body_html = \?, rendered_version = \? WHERE id = \? AND edit_count = \? AND rendered_version < \?$/';
const CR_FIND_MANY_SQL = '/FROM cms_community_posts WHERE id IN \(/';
const CR_WRITE_SQL = '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i';

/** Zitat-Block eines Beitrags im Markdown (Markerzeile plus Textzeilen), wie `QuoteService::format()` ihn schreibt. */
function crQuote(int $postId, string ...$lines): string
{
    return implode("\n", array_merge(['> [quote=post:' . $postId . ']'], array_map(static fn (string $line): string => '> ' . $line, $lines)));
}

T::run('G present(): Cache NULL wird gerendert und im Autocommit mit Wächter gespeichert, aktueller Cache bleibt, unter MIN_SERVE nie', static function (): void {
    $w = new CrWorld();
    $fresh = $w->post('**fett** und _kursiv_');
    $current = $w->post('Neuer Text', ['html' => "<p>GESPEICHERT</p>\n"]);
    $old = $w->post('Text der alten Fassung', ['html' => '<p>ALT-UNSICHER</p>', 'version' => ContentRenderer::MIN_SERVE_VERSION - 1]);
    $w->db->log = [];
    $out = $w->render->present($w->member, [$w->row($fresh), $w->row($current), $w->row($old)]);
    T::same("<p><strong>fett</strong> und <em>kursiv</em></p>\n", $out[0]['html'] ?? null, 'Cache NULL: gerendert');
    T::same(["<p><strong>fett</strong> und <em>kursiv</em></p>\n", ContentRenderer::VERSION], [$w->stored($fresh)['body_html'], (int) $w->stored($fresh)['rendered_version']], 'Cache NULL: HTML mit VERSION gespeichert');
    T::same("<p>GESPEICHERT</p>\n", $out[1]['html'] ?? null, 'aktueller Cache (rendered_version = VERSION) wird unverändert ausgeliefert');
    T::same("<p>Text der alten Fassung</p>\n", $out[2]['html'] ?? null, 'Cache unter MIN_SERVE_VERSION: neu gerendert, nie ausgeliefert');
    T::same(2, $w->purifies, 'genau zwei Rendervorgänge (Cache NULL und unter MIN_SERVE), keiner für den aktuellen Cache');
    $stores = $w->logged(CR_STORE_SQL);
    T::same(2, count($stores), 'zwei Speicherungen über storeRendered (UPDATE … WHERE id = ? AND edit_count = ? AND rendered_version < ?)', json_encode($w->db->log));
    T::same([false, false], array_map(static fn (int $i): bool => $w->db->txAt($i), $stores), 'beide im Autocommit (außerhalb von BEGIN)');
    T::same(["<p><strong>fett</strong> und <em>kursiv</em></p>\n", ContentRenderer::VERSION, $fresh, 0, ContentRenderer::VERSION], $w->db->paramsAt($stores[0] ?? -1), 'Parameter: HTML, VERSION, ID, gelesenes edit_count, VERSION (Wächter)');
    T::same($stores, $w->logged(CR_WRITE_SQL), 'sonst schreibt present() nichts');
    foreach ($out as $item) {
        T::check(!array_key_exists('body_html', $item), 'present(): kein body_html im Eintrag ' . ($item['id'] ?? '?'));
    }
    T::same('Neuer Text', $out[1]['body'] ?? null, 'body bleibt (ohne Zitate unverändert)');

    // Zweiter Aufruf: alles aktuell, kein Rendern, kein Schreiben.
    $w->db->log = [];
    $w->purifies = 0;
    $again = $w->render->present($w->member, [$w->row($fresh), $w->row($old)]);
    T::same([0, []], [$w->purifies, $w->logged(CR_WRITE_SQL)], 'zweiter Aufruf: aktueller Cache, weder Rendern noch Schreiben');
    T::same([$out[0]['html'], $out[2]['html']], [$again[0]['html'] ?? null, $again[1]['html'] ?? null], 'zweiter Aufruf: dasselbe HTML');
});

T::run('G present(): Budget je Instanz (Mitglied 20/200.000, Gast 5/50.000), flush(), offene Transaktion, Fehler beim Speichern', static function (): void {
    $w = new CrWorld();
    $ids = [];
    for ($i = 1; $i <= RenderService::LAZY_MAX_POSTS + 1; $i++) {
        $ids[] = $w->post('Beitrag Nummer ' . $i);
    }
    $rows = array_map(static fn (int $id): array => $w->row($id), $ids);
    $w->db->log = [];
    $out = $w->render->present($w->member, $rows);
    T::same(RenderService::LAZY_MAX_POSTS, $w->purifies, 'Mitglied: höchstens LAZY_MAX_POSTS = 20 Beiträge je Instanz nachgerendert');
    T::same('<p class="cms-plain">Beitrag Nummer 21</p>', $out[20]['html'] ?? null, 'der 21. Beitrag: Ausweichpfad (Klartext), ohne Rendern');
    T::same(RenderService::LAZY_MAX_POSTS, count($w->logged(CR_STORE_SQL)), 'nur die gerenderten werden gespeichert, der 21. nicht');
    T::same(null, $w->stored($ids[20])['body_html'], 'der 21. Beitrag bleibt ohne Cache');
    $w->purifies = 0;
    $w->render->present($w->member, [$w->row($ids[20])]);
    T::same(0, $w->purifies, 'Budget gilt je Instanz über Aufrufe hinweg');
    $w->render->flush();
    $w->render->present($w->member, [$w->row($ids[20])]);
    T::same(1, $w->purifies, 'flush() setzt das Budget zurück');
    $unsafe = $w->post('Klartext der alten Fassung', ['html' => '<p>ALT-UNSICHER</p>', 'version' => ContentRenderer::MIN_SERVE_VERSION - 1]);
    for ($i = 1; $i < RenderService::LAZY_MAX_POSTS; $i++) {
        $w->render->present($w->member, [$w->row($w->post('Füller ' . $i))]);
    }
    $w->purifies = 0;
    $out = $w->render->present($w->member, [$w->row($unsafe)]);
    T::same([0, '<p class="cms-plain">Klartext der alten Fassung</p>'], [$w->purifies, $out[0]['html'] ?? null], 'Budget erschöpft: HTML unter MIN_SERVE_VERSION wird nie ausgeliefert, sondern Klartext');

    // Zeichenbudget: zwei Beiträge zu je 100.005 Zeichen (Mitglied: 200.000 je Instanz).
    $w = new CrWorld();
    $long = str_repeat('Wort ', 20001);
    $a = $w->post($long);
    $b = $w->post($long);
    $out = $w->render->present($w->member, [$w->row($a), $w->row($b)]);
    T::same(1, $w->purifies, 'Mitglied: der zweite Beitrag sprengt LAZY_MAX_CHARS = 200.000 und wird nicht gerendert');
    T::check(str_starts_with((string) ($out[1]['html'] ?? ''), '<p class="cms-plain">'), 'Mitglied: zweiter langer Beitrag im Ausweichpfad');

    // Gäste: 5 Beiträge und 50.000 Zeichen (Vertrag §4.7 Nr. 4).
    $w = new CrWorld();
    $ids = [];
    for ($i = 1; $i <= RenderService::LAZY_GUEST_POSTS + 1; $i++) {
        $ids[] = $w->post('Gastbeitrag ' . $i);
    }
    $out = $w->render->present(null, array_map(static fn (int $id): array => $w->row($id), $ids));
    T::same(RenderService::LAZY_GUEST_POSTS, $w->purifies, 'Gast: höchstens LAZY_GUEST_POSTS = 5 Beiträge je Instanz');
    T::same('<p class="cms-plain">Gastbeitrag 6</p>', $out[5]['html'] ?? null, 'Gast: der 6. Beitrag im Ausweichpfad');
    $w->render->flush();
    $w->purifies = 0;
    $w->render->present(0, [$w->row($ids[5])]);
    T::same(1, $w->purifies, 'Betrachter 0 gilt als Gast, nach flush() wieder im Budget');
    $w = new CrWorld();
    $mid = str_repeat('Satz ', 6000);
    $c = $w->post($mid);
    $d = $w->post($mid);
    $w->render->present(null, [$w->row($c), $w->row($d)]);
    T::same(1, $w->purifies, 'Gast: zwei Beiträge zu je 30.000 Zeichen sprengen LAZY_GUEST_CHARS = 50.000');
    $w->render->flush();
    $w->purifies = 0;
    $w->render->present($w->member, [$w->row($c), $w->row($d)]);
    T::same(1, $w->purifies, 'Mitglied: der erste aus dem Cache (beim Gast gespeichert), der zweite im Budget des Mitglieds gerendert');

    // Offene Transaktion: rendern, aber nicht speichern.
    $w = new CrWorld();
    $p = $w->post('In der Transaktion');
    $w->db->log = [];
    $w->db->getPdo()->beginTransaction();
    $out = $w->render->present($w->member, [$w->row($p)]);
    $w->db->getPdo()->rollBack();
    T::same(["<p>In der Transaktion</p>\n", []], [$out[0]['html'] ?? null, $w->logged(CR_STORE_SQL)], 'offene Transaktion: HTML angezeigt, kein storeRendered');

    // Fehler beim Speichern (z. B. Lock-Timeout): Warnung, die Anzeige läuft weiter.
    $w = new CrWorld();
    $p = $w->post('Speichern scheitert');
    $w->db->failOn = ['/SET body_html = \?, rendered_version = \?/'];
    $out = $w->render->present($w->member, [$w->row($p)]);
    T::same("<p>Speichern scheitert</p>\n", $out[0]['html'] ?? null, 'storeRendered wirft: die Anzeige nutzt das frische HTML');

    // Ohne edit_count im Eintrag kein Schreiben (der Wächter braucht den gelesenen Stand).
    $w = new CrWorld();
    $p = $w->post('Ohne Zähler');
    $row = $w->row($p);
    unset($row['edit_count']);
    $w->db->log = [];
    $out = $w->render->present($w->member, [$row]);
    T::same(["<p>Ohne Zähler</p>\n", []], [$out[0]['html'] ?? null, $w->logged(CR_STORE_SQL)], 'Eintrag ohne edit_count: angezeigt, nicht gespeichert');
});

T::run('G present(): Wächter edit_count verhindert alten Cache (Bearbeitung und Anhangsänderung dazwischen, R7)', static function (): void {
    $w = new CrWorld();
    $posts = new PostRepository($w->db);
    // Anhang hinzugefügt oder entfernt: clearRendered() zwischen dem Lesen der Zeile und dem Speichern.
    $p = $w->post('Text mit Anhang', ['html' => '<p>ALT</p>', 'version' => 0]);
    $row = $w->row($p);
    $w->db->beforeExecute[CR_STORE_SQL] = static function () use ($posts, $p, $w): void {
        $posts->clearRendered($p, gmdate('Y-m-d H:i:s', $w->now));
    };
    $out = $w->render->present($w->member, [$row]);
    T::same("<p>Text mit Anhang</p>\n", $out[0]['html'] ?? null, 'Anzeige mit dem frisch gerenderten HTML');
    $stored = $w->stored($p);
    T::same([null, 0, 1], [$stored['body_html'], (int) $stored['rendered_version'], (int) $stored['edit_count']], 'clearRendered dazwischen: der Wächter verhindert das Speichern, der Cache bleibt NULL');

    // Bearbeitung dazwischen: die gelesene Zeile ist veraltet.
    $q = $w->post('Erste Fassung');
    $stale = $w->row($q);
    $posts->applyEdit($q, 'Zweite Fassung', $w->author, null, gmdate('Y-m-d H:i:s', $w->now));
    $w->render->present($w->member, [$stale]);
    T::same([null, 'Zweite Fassung'], [$w->stored($q)['body_html'], $w->stored($q)['body']], 'veraltetes edit_count: HTML der alten Fassung wird nicht gespeichert');
    $w->render->present($w->member, [$w->row($q)]);
    T::same("<p>Zweite Fassung</p>\n", $w->stored($q)['body_html'], 'mit aktuellem edit_count: gespeichert');
});

T::run('G present(): Zitate je Betrachter (sichtbar, entfernt, nicht sichtbar, verschachtelt), DE und EN, eine findMany-Abfrage', static function (): void {
    $w = new CrWorld();
    $visible = $w->post('Sichtbare Quelle');
    $hidden = $w->post('Ausgeblendete Quelle', ['status' => 'hidden']);
    $deleted = $w->post('Gelöschte Quelle', ['status' => 'deleted']);
    $pending = $w->post('Wartende Quelle', ['status' => 'pending']);
    $secret = $w->post('Quelle im verborgenen Forum', ['topic' => $w->secretTopic]);
    $secretHidden = $w->post('Ausgeblendet im verborgenen Forum', ['topic' => $w->secretTopic, 'status' => 'hidden']);
    $body = implode("\n\n", [
        'Vorwort',
        crQuote($visible, 'ZITAT-SICHTBAR'),
        crQuote($hidden, 'GEHEIM-AUSGEBLENDET', '', '> GEHEIM-VERSCHACHTELT', '', 'GEHEIM-NACH-DER-SCHACHTEL'),
        crQuote($deleted, 'GEHEIM-GELOESCHT'),
        crQuote($pending, 'GEHEIM-WARTEND'),
        crQuote($secret, 'GEHEIM-VERBORGEN'),
        crQuote($secretHidden, 'GEHEIM-VERBORGEN-AUSGEBLENDET'),
        crQuote(999999, 'GEHEIM-UNBEKANNT'),
        'Nachwort',
    ]);
    $citing = $w->post($body, ['user' => $w->member]);
    $citingRow = $w->row($citing);
    $w->db->log = [];
    $out = $w->render->present($w->member, [$citingRow], 'de');
    $html = (string) ($out[0]['html'] ?? '');
    T::check(!str_contains($html, 'GEHEIM'), 'kein Text einer nicht sichtbaren, entfernten oder unbekannten Quelle im HTML', $html);
    T::check(str_contains($html, 'ZITAT-SICHTBAR'), 'die sichtbare Quelle bleibt lesbar');
    T::check(str_contains($html, '<blockquote class="cms-quote" data-quote-post="' . $visible . '"><p class="cms-quote__source"><a href="' . htmlspecialchars(Urls::post($visible, 'de')) . '">Zum zitierten Beitrag</a></p>'), 'sichtbar: Quellverweis mit Urls::post() direkt nach dem öffnenden Tag', $html);
    $block = static fn (int $id, string $notice): string => '<blockquote class="cms-quote cms-quote--hidden" data-quote-post="' . $id . '"><p>' . $notice . '</p></blockquote>';
    foreach ([$hidden => QuoteService::REMOVED_NOTICE, $deleted => QuoteService::REMOVED_NOTICE, $pending => QuoteService::HIDDEN_NOTICE, $secret => QuoteService::HIDDEN_NOTICE, $secretHidden => QuoteService::HIDDEN_NOTICE, 999999 => QuoteService::HIDDEN_NOTICE] as $id => $notice) {
        T::check(str_contains($html, $block($id, $notice)), 'Platzhalter für Quelle ' . $id . ': ' . $notice, $html);
    }
    T::same(1, substr_count($html, 'data-quote-post="' . $hidden . '"'), 'verschachtelter Block wird als Ganzes ersetzt (ein öffnendes Tag, kein Rest)');
    T::check(!str_contains($html, 'GEHEIM-NACH-DER-SCHACHTEL'), 'auch der Text hinter dem verschachtelten Blockzitat gehört zum ersetzten Block');
    T::same(7, substr_count($html, '<blockquote'), 'sieben Zitat-Blöcke, vom verschachtelten Blockzitat bleibt nichts');
    T::same(1, count($w->logged(CR_FIND_MANY_SQL)), 'alle Zitate der Seite über genau eine findMany-Abfrage');
    T::same([], $w->logged('/FROM cms_community_posts WHERE id = \?/'), 'keine Einzelabfrage je Zitat');
    $reads = array_values(array_filter($w->canLog, static fn (array $c): bool => $c['action'] === 'read'));
    T::check($reads !== [] && array_reduce($reads, static fn (bool $ok, array $c): bool => $ok && ($c['context']['now'] ?? null) === $w->now && isset($c['context']['post']), true), 'jede Leseprüfung der Quellen bekommt now und die Zeile');
    T::same(1, $w->clockReads, 'present(): die Uhr wird einmal gelesen');

    $text = (string) ($out[0]['body'] ?? '');
    T::check(!str_contains($text, 'GEHEIM') && str_contains($text, 'ZITAT-SICHTBAR'), 'body ist neutralisiert (§1 Nr. 9): sichtbar bleibt, Geheimes fehlt', $text);
    T::check(str_contains($text, "> [quote=post:{$hidden}]\n> " . QuoteService::REMOVED_NOTICE), 'body: entfernte Quelle als Platzhalter');
    T::check(!array_key_exists('body_html', $out[0]), 'kein body_html');

    $en = (string) ($w->render->present($w->member, [$w->row($citing)], 'en')[0]['html'] ?? '');
    T::check(str_contains($en, $block($hidden, '[Quote removed]')) && str_contains($en, $block($secret, '[Quote from an area you cannot see]')), 'EN: Platzhalter in der Sprache des Betrachters', $en);
    T::check(str_contains($en, '<a href="' . htmlspecialchars(Urls::post($visible, 'en')) . '">Go to the quoted post</a>'), 'EN: Quellverweis mit EN-URL und EN-Text', $en);

    // Moderation: sieht ausgeblendete Quellen voll, gelöschte bleiben entfernt.
    $mod = (string) ($w->render->present($w->moderator, [$w->row($citing)])[0]['html'] ?? '');
    T::check(str_contains($mod, '<p>GEHEIM-VERBORGEN</p>'), 'Moderation: Quelle im verborgenen Forum lesbar', $mod);
    T::check(!str_contains($mod, 'GEHEIM-AUSGEBLENDET') && !str_contains($mod, 'GEHEIM-VERBORGEN-AUSGEBLENDET') && str_contains($mod, $block($hidden, QuoteService::REMOVED_NOTICE)), 'Moderation: ausgeblendete Quelle erscheint als „entfernt“ (der Status entscheidet nach dem Recht)', $mod);
});

T::run('G present(): Datenbankfehler ⇒ alle Zitate verborgen; Zitatgrenzen (20 je Text, 200 Auflösungen je Aufruf)', static function (): void {
    $w = new CrWorld();
    $visible = $w->post('Sichtbar');
    $citing = $w->post(crQuote($visible, 'ZITAT-SICHTBAR') . "\n\nAntwort", ['user' => $w->member]);
    $w->db->failOn = [CR_FIND_MANY_SQL];
    $out = $w->render->present($w->member, [$w->row($citing)]);
    $html = (string) ($out[0]['html'] ?? '');
    T::check(!str_contains($html, 'ZITAT-SICHTBAR') && str_contains($html, QuoteService::HIDDEN_NOTICE), 'Fehler der Auflösung: auch sichtbare Quellen gelten als nicht sichtbar (fail-closed)', $html);
    T::check(!str_contains((string) ($out[0]['body'] ?? ''), 'ZITAT-SICHTBAR'), 'auch body ist fail-closed neutralisiert');
    T::check(str_contains($html, '<p>Antwort</p>'), 'der eigene Text bleibt sichtbar');

    // 25 Zitat-Blöcke in einem Text: höchstens MAX_QUOTES = 20 IDs gehen in findMany, der Überlauf erscheint als „[…]“.
    $w = new CrWorld();
    $sources = [];
    for ($i = 1; $i <= 25; $i++) {
        $sources[] = $w->post('Quelle ' . $i);
    }
    $citing = $w->post(implode("\n\n", array_map(static fn (int $id): string => crQuote($id, 'QUELLTEXT-' . $id), $sources)), ['user' => $w->member]);
    $w->db->log = [];
    $html = (string) ($w->render->present($w->member, [$w->row($citing)])[0]['html'] ?? '');
    $asked = $w->loggedParams(CR_FIND_MANY_SQL);
    T::same(array_slice($sources, 0, ContentRenderer::MAX_QUOTES), $asked, '25 Marker: genau die ersten 20 IDs in findMany');
    T::same(ContentRenderer::MAX_QUOTES, substr_count($html, 'cms-quote__source'), '20 sichtbare Zitate mit Quellverweis');
    T::same(5, substr_count($html, '<blockquote class="cms-quote cms-quote--hidden"><p>' . QuoteService::OMISSION . '</p></blockquote>'), '5 Überlauf-Blöcke „[…]“ ohne Kennzeichen');
    foreach (array_slice($sources, ContentRenderer::MAX_QUOTES) as $id) {
        T::check(!str_contains($html, 'QUELLTEXT-' . $id . '<'), 'Überlauf: kein Text der Quelle ' . $id);
    }

    // Mehr als 200 IDs in einem Aufruf: die ersten 200 werden aufgelöst, der Rest gilt ohne Abfrage als nicht sichtbar.
    $w = new CrWorld();
    $sources = [];
    for ($i = 1; $i <= 220; $i++) {
        $sources[] = $w->post('Q' . $i);
    }
    $items = [];
    foreach (array_chunk($sources, 20) as $chunk) {
        $items[] = $w->row($w->post(implode("\n\n", array_map(static fn (int $id): string => crQuote($id, 'LESBAR-' . $id), $chunk)), ['user' => $w->member]));
    }
    $w->db->log = [];
    $out = $w->render->present($w->member, $items);
    $asked = $w->loggedParams(CR_FIND_MANY_SQL);
    T::same(array_slice($sources, 0, RenderService::MAX_QUOTE_LOOKUPS), $asked, 'höchstens MAX_QUOTE_LOOKUPS = 200 IDs je Aufruf in findMany (zwei Stücke zu 100)');
    T::same(2, count($w->logged(CR_FIND_MANY_SQL)), 'zwei Stücke zu je 100 IDs');
    $last = (string) ($out[10]['html'] ?? '');
    T::same([0, 20], [substr_count($last, 'cms-quote__source'), substr_count($last, '<p>' . QuoteService::HIDDEN_NOTICE . '</p>')], 'IDs 201 bis 220: verborgen, obwohl lesbar (feste Kosten je Seite)');
    T::check(!str_contains($last, 'LESBAR-'), 'IDs über der Grenze: kein Text der Quelle');
    T::same(20, substr_count((string) ($out[9]['html'] ?? ''), 'cms-quote__source'), 'IDs 181 bis 200: noch aufgelöst und sichtbar');
});

T::run('G Lecktests (S1/S2): json_encode von present()/presentRow() ohne Geheimtext bei Cache, Cache NULL, Budget erschöpft, Purifier fehlt, Cache unter MIN_SERVE', static function (): void {
    $scenarios = ['Cache', 'Cache NULL', 'Budget erschöpft', 'Purifier fehlt', 'Cache unter MIN_SERVE'];
    foreach ($scenarios as $scenario) {
        foreach (['Mitglied', 'Gast'] as $who) {
            $w = new CrWorld();
            $viewer = $who === 'Mitglied' ? $w->member : null;
            $visible = $w->post('Sichtbar');
            $secret = $w->post('Quelle', ['topic' => $w->secretTopic]);
            $body = "Ich zitiere:\n\n" . crQuote($secret, 'GEHEIMTEXT eins', 'GEHEIMTEXT zwei') . "\n\n" . crQuote($visible, 'LESBARES ZITAT') . "\n\nEnde";
            $citing = $w->post($body, ['user' => $w->member]);
            $render = new ContentRenderer();
            $full = (string) $render->render($body, ['post_id' => $citing])['html'];
            T::check(str_contains($full, 'GEHEIMTEXT'), "{$scenario}/{$who}: Vorbedingung, das gespeicherte HTML enthält den Geheimtext");
            switch ($scenario) {
                case 'Cache':
                    $w->db->execute('UPDATE cms_community_posts SET body_html = ?, rendered_version = ? WHERE id = ?', [$full, ContentRenderer::VERSION, $citing]);
                    break;
                case 'Budget erschöpft':
                    for ($i = 0; $i < RenderService::LAZY_MAX_POSTS; $i++) {
                        $w->render->present($viewer, [$w->row($w->post('Füller ' . $i))]);
                    }
                    break;
                case 'Purifier fehlt':
                    $w->purifierMissing = true;
                    break;
                case 'Cache unter MIN_SERVE':
                    $w->db->execute('UPDATE cms_community_posts SET body_html = ?, rendered_version = ? WHERE id = ?', [$full, ContentRenderer::MIN_SERVE_VERSION - 1, $citing]);
                    for ($i = 0; $i < RenderService::LAZY_MAX_POSTS; $i++) {
                        $w->render->present($viewer, [$w->row($w->post('Füller ' . $i))]);
                    }
                    break;
            }
            $items = $w->listPage($viewer);
            $presented = $w->render->present($viewer, $items);
            $json = (string) json_encode($presented, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            T::check(!str_contains($json, 'GEHEIMTEXT'), "{$scenario}/{$who}: json_encode(present(listPage-Einträge)) enthält den Geheimtext nicht", $json);
            T::check(str_contains($json, 'LESBARES ZITAT'), "{$scenario}/{$who}: das lesbare Zitat bleibt lesbar (Entscheidungen statt pauschal verborgen)");
            T::check(!str_contains($json, '"body_html"'), "{$scenario}/{$who}: kein body_html in der Ausgabe");
            $mine = array_values(array_filter($presented, static fn (array $item): bool => ($item['id'] ?? 0) === $citing))[0] ?? [];
            T::check(is_string($mine['html'] ?? null) && str_contains($mine['html'], 'Ende'), "{$scenario}/{$who}: der zitierende Beitrag hat html");
            if ($scenario !== 'Cache') {
                T::check(str_starts_with((string) ($mine['html'] ?? ''), $scenario === 'Cache NULL' ? '<p>Ich zitiere:</p>' : '<p class="cms-plain">'), "{$scenario}/{$who}: erwarteter Weg (" . ($scenario === 'Cache NULL' ? 'nachgerendert' : 'Ausweichpfad') . ')', (string) ($mine['html'] ?? ''));
            }
            $row = $w->render->presentRow($viewer, $w->row($citing));
            T::check(!str_contains($row, 'GEHEIMTEXT') && str_contains($row, 'LESBARES ZITAT'), "{$scenario}/{$who}: presentRow() ohne Geheimtext, lesbares Zitat bleibt", $row);
        }
    }
});

T::run('G present(): Filter cms_community_render_post erhält neutralisiertes HTML und die ID; Ausnahme oder Nicht-String ⇒ Eingang; nie gespeichert', static function (): void {
    $w = new CrWorld();
    $secret = $w->post('Quelle', ['topic' => $w->secretTopic]);
    $p = $w->post(crQuote($secret, 'GEHEIM') . "\n\nText", ['user' => $w->member]);
    $w->filter = static fn (string $html, int $postId): string => $html . '<p>FILTER ' . $postId . '</p>';
    $out = $w->render->present($w->member, [$w->row($p)]);
    T::same(1, count($w->filterLog), 'der Filter läuft einmal je vollem Eintrag');
    T::same($p, $w->filterLog[0]['post_id'] ?? null, 'Filter erhält die Beitrags-ID');
    T::check(!str_contains($w->filterLog[0]['html'] ?? 'GEHEIM', 'GEHEIM') && str_contains($w->filterLog[0]['html'] ?? '', QuoteService::HIDDEN_NOTICE), 'Filter erhält das neutralisierte HTML (nach der Zitatauflösung)');
    T::check(str_ends_with((string) ($out[0]['html'] ?? ''), '<p>FILTER ' . $p . '</p>'), 'Ergebnis des Filters wird ausgegeben');
    T::check(!str_contains((string) $w->stored($p)['body_html'], 'FILTER'), 'Ergebnis des Filters wird nicht gespeichert');

    $expected = (string) ($w->render->present($w->member, [$w->row($p)])[0]['html'] ?? '');
    $w->filter = static fn (string $html, int $postId): int => 42;
    T::same(str_replace('<p>FILTER ' . $p . '</p>', '', $expected), $w->render->present($w->member, [$w->row($p)])[0]['html'] ?? null, 'Filter liefert keinen String: Eingang wird verwendet');
    $w->filter = static function (string $html, int $postId): string {
        throw new \RuntimeException('Plugin kaputt');
    };
    T::same(str_replace('<p>FILTER ' . $p . '</p>', '', $expected), $w->render->present($w->member, [$w->row($p)])[0]['html'] ?? null, 'Filter wirft: Eingang wird verwendet, present() wirft nicht');
    $w->filter = null;
    $w->filterLog = [];
    $w->render->present($w->member, [['view' => 'placeholder', 'id' => 9, 'status' => 'hidden']]);
    T::same([], $w->filterLog, 'Platzhalter: kein Filteraufruf');
});

T::run('G present(): Eintragsformen (Platzhalter, Zeile ohne view, fremde Felder) und quoteSpans()', static function (): void {
    $w = new CrWorld();
    $placeholder = ['view' => 'placeholder', 'id' => 3, 'topic_id' => $w->topic, 'status' => 'hidden', 'body' => 'roh', 'body_html' => '<p>roh</p>', 'html' => '<p>roh</p>'];
    $out = $w->render->present($w->member, [$placeholder]);
    T::same(['view' => 'placeholder', 'id' => 3, 'topic_id' => $w->topic, 'status' => 'hidden'], $out[0] ?? null, 'Platzhalter: weder Text noch HTML (fail-closed)');
    T::same([], $w->render->present($w->member, []), 'leere Liste: leere Liste');
    T::same('', $w->render->presentRow($w->member, $placeholder), 'presentRow() für einen Platzhalter: leer');
    $p = $w->post('Zeile');
    T::same("<p>Zeile</p>\n", $w->render->presentRow($w->member, $w->row($p)), 'presentRow(): Beitragszeile ohne view gilt als voll');

    $html = '<p>a</p><blockquote><p>normal</p></blockquote><blockquote data-quote-post="7" class="cms-quote"><p>x</p><blockquote><p>y</p></blockquote><p>z</p></blockquote>'
        . '<blockquote class="cms-quote cms-quote--hidden"><p>[…]</p></blockquote><BLOCKQUOTE class="cms-quote" data-quote-post="12"><p>w</p></BLOCKQUOTE>';
    $spans = RenderService::quoteSpans($html);
    T::same([7, 12], array_column($spans, 'id'), 'quoteSpans(): nur Blöcke mit data-quote-post, Attributreihenfolge und Groß-/Kleinschreibung beliebig');
    $first = $spans[0] ?? ['start' => 0, 'open_end' => 0, 'end' => 0];
    T::same('<blockquote data-quote-post="7" class="cms-quote">', substr($html, $first['start'], $first['open_end'] - $first['start']), 'quoteSpans(): start/open_end umfassen das öffnende Tag');
    T::same('<blockquote data-quote-post="7" class="cms-quote"><p>x</p><blockquote><p>y</p></blockquote><p>z</p></blockquote>', substr($html, $first['start'], $first['end'] - $first['start']), 'quoteSpans(): end liegt hinter dem passenden </blockquote> (verschachtelte gezählt)');
    $open = RenderService::quoteSpans('<blockquote class="cms-quote" data-quote-post="5"><p>offen');
    T::same([['id' => 5, 'start' => 0, 'open_end' => 50, 'end' => 58]], $open, 'quoteSpans(): fehlt das schließende Tag, reicht der Block bis zum Ende (fail-closed)');
    T::same([], RenderService::quoteSpans('<blockquote class="cms-quote" data-quote-post="0x5"><p>x</p></blockquote>'), 'quoteSpans(): nur Dezimalzahlen ab 1');
});

T::run('G QuoteService: resolveMany(), quotableMany(), isQuotable() und resolver() (eine Regel, eine Abfrage, fail-closed)', static function (): void {
    $w = new CrWorld();
    $quotes = new QuoteService($w->db, $w->settings, null, fn (?int $u, string $a, array $c): AccessDecision => $w->access->can($u, $a, $c));
    $visible = $w->post('Sichtbar');
    $hidden = $w->post('Ausgeblendet', ['status' => 'hidden']);
    $deleted = $w->post('Gelöscht', ['status' => 'deleted']);
    $pending = $w->post('Wartend', ['status' => 'pending']);
    $rejected = $w->post('Abgelehnt', ['status' => 'rejected']);
    $secret = $w->post('Verborgen', ['topic' => $w->secretTopic]);
    $secretHidden = $w->post('Verborgen ausgeblendet', ['topic' => $w->secretTopic, 'status' => 'hidden']);
    $ids = [$visible, $hidden, $deleted, $pending, $rejected, $secret, $secretHidden, 999999];
    $expected = [$visible => true, $hidden => QuoteService::REMOVED, $deleted => QuoteService::REMOVED, $pending => false, $rejected => false, $secret => false, $secretHidden => false, 999999 => false];

    $w->db->log = [];
    T::same($expected, $quotes->resolveMany($w->member, $ids, $w->now), 'resolveMany(): sichtbar, entfernt, nicht sichtbar, unbekannt; ausgeblendet im nicht lesbaren Forum ⇒ nicht sichtbar (nie „entfernt“)');
    T::same(1, count($w->logged(CR_FIND_MANY_SQL)), 'resolveMany(): eine findMany-Abfrage für alle IDs');
    T::same([], $w->logged('/FROM cms_community_posts WHERE id = \?/'), 'resolveMany(): keine Einzelabfrage eines Beitrags');
    $resolver = $quotes->resolver($w->member);
    T::same($expected, array_combine($ids, array_map($resolver, $ids)), 'resolver(): dieselbe Regel (stateOf)');
    T::same([$visible => true, $hidden => QuoteService::REMOVED, $secret => true, $secretHidden => QuoteService::REMOVED], $quotes->resolveMany($w->moderator, [$visible, $hidden, $secret, $secretHidden], $w->now), 'resolveMany() Moderation: verborgenes Forum lesbar, ausgeblendet ⇒ entfernt');
    T::same([$visible => true], $quotes->resolveMany(null, [$visible, $visible, 0, -3, '7', 1.5], $w->now), 'resolveMany(): nur ganze Zahlen ≥ 1, doppelte einmal; Gast liest das offene Forum');
    T::same([], $quotes->resolveMany($w->member, [], $w->now), 'resolveMany(): leere Liste ohne Abfrage');
    $w->db->failOn = [CR_FIND_MANY_SQL];
    T::same(array_fill_keys([$visible, $hidden], false), $quotes->resolveMany($w->member, [$visible, $hidden], $w->now), 'resolveMany(): Datenbankfehler ⇒ alle false');
    T::same(array_fill_keys([$visible], false), $quotes->quotableMany($w->member, [$visible], $w->now), 'quotableMany(): Datenbankfehler ⇒ alle false');
    T::same(false, $quotes->isQuotable($visible, $w->member), 'isQuotable(): Datenbankfehler ⇒ nicht zitierbar');
    $w->db->failOn = [];

    $w->db->log = [];
    T::same([$visible => true, $hidden => false, $deleted => false, $pending => false, $rejected => false, $secret => false, $secretHidden => false, 999999 => false], $quotes->quotableMany($w->member, $ids, $w->now), 'quotableMany() Mitglied: nur veröffentlicht und lesbar');
    T::same(1, count($w->logged(CR_FIND_MANY_SQL)), 'quotableMany(): eine findMany-Abfrage');
    T::same([$visible => true, $hidden => true, $deleted => false, $pending => false, $secret => true, $secretHidden => true], $quotes->quotableMany($w->moderator, [$visible, $hidden, $deleted, $pending, $secret, $secretHidden], $w->now), 'quotableMany() Moderation: ausgeblendet zitierbar, gelöscht und wartend nicht');
    T::same([$visible => true, $hidden => false], [$visible => $quotes->isQuotable($visible, $w->member), $hidden => $quotes->isQuotable($hidden, $w->member)], 'isQuotable(): dieselbe Regel wie quotableMany()');
    T::same(true, $quotes->isQuotable($hidden, $w->moderator), 'isQuotable(): Moderation zitiert Ausgeblendetes');
    T::same(false, $quotes->isQuotable(0, $w->member), 'isQuotable(0): nein');
    T::same(null, $quotes->build($hidden, $w->member), 'build(): ausgeblendet für Mitglieder nicht zitierbar (Regel unverändert)');
    T::same(crQuote($hidden, 'Ausgeblendet'), $quotes->build($hidden, $w->moderator), 'build(): Moderation zitiert Ausgeblendetes mit Text');
});

T::run('G step(): Fenster, STEP_LIMIT, Zeichenbudget, Fortschritt, done, Modus all, Fehlerzeile', static function (): void {
    $w = new CrWorld();
    $insert = static function (int $id, string $body, ?string $html = null, int $version = 0) use ($w): void {
        $w->db->execute('INSERT INTO cms_community_posts (id, topic_id, forum_id, user_id, is_first_post, status, body, body_html, rendered_version, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?)', [$id, $w->topic, $w->open, $w->author, 'published', $body, $html, $version, '2026-11-15 10:00:00', '2026-11-15 10:00:00']);
    };
    foreach ([1, 2, 3, 6000, 6001] as $id) {
        $insert($id, 'Beitrag ' . $id);
    }
    $insert(4, 'Aktuell', "<p>ALT-AKTUELL</p>\n", ContentRenderer::VERSION);
    $insert(5, "kaputt \xFF");
    $state = ['after_id' => 0, 'max_id' => 6001, 'all' => false, 'version' => ContentRenderer::VERSION];
    $w->db->log = [];
    $first = $w->render->step($state);
    $batch = $w->logged('/FROM cms_community_posts WHERE id > \? AND id <= \? AND rendered_version < \?/');
    T::same([0, 5000, ContentRenderer::VERSION, RenderService::STEP_LIMIT], $w->db->paramsAt($batch[0] ?? -1), 'renderBatch(after, after + STEP_WINDOW, VERSION, STEP_LIMIT): nur das Fenster bis 5000');
    T::same(['processed' => 5000, 'failed' => 1, 'done' => false], array_intersect_key($first, ['processed' => 0, 'failed' => 0, 'done' => 0]), 'erster Schritt: Fortschritt 5000 IDs, eine Fehlerzeile (ungültiges UTF-8), nicht fertig');
    T::same(5000, $first['state']['after_id'] ?? null, 'after_id rückt auf das Fensterende');
    T::same(['<p>Beitrag 1</p>' . "\n", "<p>ALT-AKTUELL</p>\n", null, null], [$w->stored(1)['body_html'], $w->stored(4)['body_html'], $w->stored(5)['body_html'], $w->stored(6000)['body_html']], 'gerendert im Fenster; aktuelle Zeile unberührt; Fehlerzeile übersprungen; außerhalb des Fensters nichts');
    T::same([false], array_values(array_unique(array_map(static fn (int $i): bool => $w->db->txAt($i), $w->logged(CR_STORE_SQL)))), 'Speichern im Autocommit');
    $second = $w->render->step($first['state']);
    T::same(['processed' => 1001, 'done' => true, 'after_id' => 6001], ['processed' => $second['processed'], 'done' => $second['done'], 'after_id' => $second['state']['after_id']], 'zweiter Schritt: bis max_id, fertig');
    T::same('', $second['message'], 'message leer (die Oberfläche formatiert aus dem Zustand)');
    T::same(['processed' => 0, 'done' => true], array_intersect_key($w->render->step($second['state']), ['processed' => 0, 'done' => 0]), 'nach dem Ende: nichts zu tun, fertig');

    // Modus all: auch aktuelle Zeilen (rendered_version < VERSION + 1), mit replaceCurrent.
    $w->db->log = [];
    $w->render->step(['after_id' => 3, 'max_id' => 4, 'all' => true, 'version' => ContentRenderer::VERSION]);
    T::same([3, 4, ContentRenderer::VERSION + 1, RenderService::STEP_LIMIT], $w->db->paramsAt($w->logged('/FROM cms_community_posts WHERE id > \?/')[0] ?? -1), 'Modus all: renderBatch mit VERSION + 1');
    T::same("<p>Aktuell</p>\n", $w->stored(4)['body_html'], 'Modus all: aktuelle Zeile neu gerendert (replaceCurrent)');

    // STEP_LIMIT: 55 Beiträge im Fenster ⇒ 50 je Schritt, after_id = letzte bearbeitete ID.
    $w = new CrWorld();
    $ids = [];
    for ($i = 0; $i < 55; $i++) {
        $ids[] = $w->post('Massenbeitrag ' . $i);
    }
    $max = max($ids);
    $one = $w->render->step(['after_id' => 0, 'max_id' => $max, 'all' => false]);
    T::same([$ids[49], $ids[49], false], [$one['state']['after_id'], $one['processed'], $one['done']], 'STEP_LIMIT = 50: after_id = 50. bearbeitete ID');
    $two = $w->render->step($one['state']);
    T::same([$max, true], [$two['state']['after_id'], $two['done']], 'zweiter Schritt: Rest, fertig');
    T::same(55, (int) $w->db->get_var("SELECT COUNT(*) FROM cms_community_posts WHERE rendered_version = ? AND body_html IS NOT NULL AND body LIKE 'Massenbeitrag%'", [ContentRenderer::VERSION]), 'alle 55 gerendert');

    // Zeichenbudget STEP_MAX_CHARS = 500.000: zwei Beiträge zu je 260.000 Zeichen ⇒ ein Beitrag je Schritt.
    $w = new CrWorld();
    $a = $w->post(str_repeat('Wort ', 52000));
    $b = $w->post(str_repeat('Satz ', 52000));
    $one = $w->render->step(['after_id' => 0, 'max_id' => $b, 'all' => false]);
    T::same([$a, false], [$one['state']['after_id'], $one['done']], 'Zeichenbudget: Abbruch innerhalb des Fensters nach dem ersten Beitrag');
    T::same(null, $w->stored($b)['body_html'], 'Zeichenbudget: der zweite Beitrag wartet auf den nächsten Schritt');
    $two = $w->render->step($one['state']);
    T::same([$b, true], [$two['state']['after_id'], $two['done']], 'nächster Schritt: der zweite Beitrag (mindestens einer je Schritt)');

    // Purifier fehlt: jede Zeile zählt als failed, der Job läuft trotzdem durch.
    $w = new CrWorld();
    $p = $w->post('Ohne Purifier');
    $w->purifierMissing = true;
    $done = $w->render->step(['after_id' => 0, 'max_id' => $p, 'all' => false]);
    T::same([1, true, null], [$done['failed'], $done['done'], $w->stored($p)['body_html']], 'Purifier fehlt: failed, kein Schreiben, Ende');
});

T::run('G Job: startJob(), runJobStep(), cancelJob(), jobStatus(), cronTick() mit Fake-BatchJobService', static function (): void {
    $w = new CrWorld();
    $p1 = $w->post('Eins');
    $p2 = $w->post('Zwei');
    $started = $w->render->startJob();
    T::same(['start', RenderService::JOB_TYPE, 'v' . ContentRenderer::VERSION, $p2, ['after_id' => 0, 'max_id' => $p2, 'all' => false, 'version' => ContentRenderer::VERSION], null], $w->jobs->calls[0] ?? null, 'startJob(): Typ community_render, Scope v<VERSION>, total = maxId, Startzustand');
    T::same([true, false], [$started['success'] ?? null, $started['resumed'] ?? null], 'startJob(): neu');
    T::same(true, $w->render->startJob()['resumed'] ?? null, 'startJob() erneut: der aktive Job wird fortgesetzt');
    $all = $w->render->startJob(true, $w->moderator);
    T::same(['v' . ContentRenderer::VERSION . ':all', true, $w->moderator], [$w->jobs->calls[2][2] ?? null, $w->jobs->calls[2][4]['all'] ?? null, $w->jobs->calls[2][5] ?? null], 'startJob(true, user): Scope v<VERSION>:all, Zustand all, Ersteller');
    $id = (int) ($started['job']['id'] ?? 0);
    $stepped = $w->render->runJobStep($id);
    T::same([true, 'done', $p2], [$stepped['success'] ?? null, $stepped['job']['status'] ?? null, $stepped['job']['processed'] ?? null], 'runJobStep(): ein Schritt über BatchJobService::step(), Ergebnis toPublic()');
    T::same("<p>Zwei</p>\n", $w->stored($p2)['body_html'], 'runJobStep(): gerendert');
    $w->jobs->jobs[99] = ['id' => 99, 'type' => 'other_job', 'scope' => '', 'status' => 'running', 'total' => 0, 'processed' => 0, 'failed' => 0, 'state' => []];
    T::same(['invalid_input', 'invalid_input', 'invalid_input'], [$w->render->runJobStep(99)['error'] ?? null, $w->render->runJobStep(12345)['error'] ?? null, $w->render->runJobStep(0)['error'] ?? null], 'runJobStep(): fremder Typ, unbekannte ID, 0 ⇒ invalid_input');
    T::same(['invalid_input', true, false], [$w->render->cancelJob(99)['error'] ?? null, $w->render->cancelJob((int) $all['job']['id'])['success'] ?? null, $w->render->cancelJob((int) $all['job']['id'])['success'] ?? null], 'cancelJob(): fremder Typ abgelehnt, eigener abgebrochen, zweimal nicht');
    T::same([(int) $all['job']['id'], 'cancelled'], [$w->render->jobStatus()['id'] ?? null, $w->render->jobStatus()['status'] ?? null], 'jobStatus(): ohne aktiven Job der jüngste dieser VERSION (beide Scopes)');
    $w->render->startJob();
    T::same(['pending'], [$w->render->jobStatus()['status'] ?? null], 'jobStatus(): ein aktiver Job geht vor');
    $w->jobs->throw = new \RuntimeException('batch_jobs fehlt');
    T::same(['success' => false, 'error' => 'db_error'], $w->render->startJob(), 'startJob(): Fehler ⇒ db_error');

    // cronTick(): fertiger Job ⇒ nichts; sonst Start und Schritte bis zum Ende; busy ⇒ Ende; wirft nie.
    $w = new CrWorld();
    $p = $w->post('Für den Cron');
    $w->render->cronTick();
    T::same(['latest', 'start', 'step'], array_map(static fn (array $c): string => $c[0], $w->jobs->calls), 'cronTick(): kein fertiger Job ⇒ start und Schritte bis fertig');
    T::same("<p>Für den Cron</p>\n", $w->stored($p)['body_html'], 'cronTick(): gerendert');
    $w->jobs->calls = [];
    $w->render->cronTick();
    T::same(['latest'], array_map(static fn (array $c): string => $c[0], $w->jobs->calls), 'cronTick(): fertiger Job dieser VERSION ⇒ nichts');
    $w = new CrWorld();
    for ($i = 0; $i < 3; $i++) {
        $w->post('Beitrag ' . $i);
    }
    $w->jobs->busy = true;
    $w->render->cronTick();
    T::same(1, count(array_filter($w->jobs->calls, static fn (array $c): bool => $c[0] === 'step')), 'cronTick(): busy ⇒ nach dem ersten Schritt Ende');
    $w->jobs->throw = new \RuntimeException('kaputt');
    $w->jobs->jobs = [];
    $w->render->cronTick();
    T::check(true, 'cronTick(): ein Fehler wirft nicht');
    // Viele Schritte: höchstens CRON_STEPS je Lauf.
    $w = new CrWorld();
    $w->db->execute('INSERT INTO cms_community_posts (id, topic_id, forum_id, user_id, is_first_post, status, body, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)', [RenderService::STEP_WINDOW * (RenderService::CRON_STEPS + 5), $w->topic, $w->open, $w->author, 'published', 'weit hinten', '2026-11-15 10:00:00', '2026-11-15 10:00:00']);
    $w->render->cronTick();
    T::same(RenderService::CRON_STEPS, count(array_filter($w->jobs->calls, static fn (array $c): bool => $c[0] === 'step')), 'cronTick(): höchstens CRON_STEPS = 20 Schritte je Lauf');
});

T::run('G preview(): Schreibrecht am Ziel, Grenzgründe erlaubt, Drossel, Eingaben, nichts geschrieben', static function (): void {
    $w = new CrWorld(['post_max' => 200, 'edit_window_minutes' => 0]);
    $own = $w->post('Eigener Beitrag', ['user' => $w->member]);
    $foreign = $w->post('Fremder Beitrag');
    $w->attachments = [
        5 => ['id' => 5, 'post_id' => $own, 'user_id' => $w->member, 'mime_type' => 'image/png', 'width' => 10, 'height' => 10, 'has_preview' => 0],
        6 => ['id' => 6, 'post_id' => $foreign, 'user_id' => $w->author, 'mime_type' => 'image/png', 'width' => 10, 'height' => 10, 'has_preview' => 0],
    ];
    foreach (['leer' => [], 'zwei Ziele' => ['forum_id' => $w->open, 'topic_id' => $w->topic], 'unbekannt' => ['thread_id' => 1], 'Text statt Zahl' => ['topic_id' => (string) $w->topic], 'null' => ['topic_id' => 0], 'negativ' => ['post_id' => -1], 'Liste' => [$w->topic]] as $name => $target) {
        T::same('invalid_input', $w->render->preview($w->member, 'Text', $target)['error'] ?? null, 'Ziel ' . $name . ' ⇒ invalid_input');
    }
    T::same('invalid_input', $w->render->preview($w->member, "\xFF", ['topic_id' => $w->topic])['error'] ?? null, 'ungültiges UTF-8 ⇒ invalid_input');
    T::same('body_too_short', $w->render->preview($w->member, "  \u{200B} ", ['topic_id' => $w->topic])['error'] ?? null, 'leer nach Normalisierung ⇒ body_too_short (post_min)');
    T::same('body_too_long', $w->render->preview($w->member, str_repeat('x', 201), ['topic_id' => $w->topic])['error'] ?? null, 'über post_max ⇒ body_too_long');
    T::same([0, [], 0], [$w->clockReads, $w->canLog, $w->purifies], 'Eingabefehler: keine Uhr, keine Rechteprüfung, kein Parsen');

    T::same('login_required', $w->render->preview(null, 'Gastvorschau', ['topic_id' => $w->topic])['error'] ?? null, 'Gast ⇒ login_required');
    T::same('login_required', $w->render->preview(0, 'Gastvorschau', ['forum_id' => $w->open])['error'] ?? null, 'Betrachter 0 gilt als Gast');
    T::same('banned_write', $w->render->preview($w->banned, 'Gesperrt', ['topic_id' => $w->topic])['error'] ?? null, 'Schreibsperre ⇒ banned_write (Lesen allein genügt nicht)');
    T::same('banned_write', $w->render->preview($w->banned, 'Gesperrt', ['forum_id' => $w->open])['error'] ?? null, 'Schreibsperre beim neuen Thema ⇒ banned_write');
    T::same('not_found', $w->render->preview($w->member, 'Verborgen', ['topic_id' => $w->secretTopic])['error'] ?? null, 'Ziel im verborgenen Forum ⇒ not_found');
    // 404-Regel (Vertrag §1 Nr. 8): fehlendes und unsichtbares Ziel ergeben dasselbe Ergebnis, je Zielart.
    $secretPost = $w->post('Beitrag im verborgenen Forum', ['topic' => $w->secretTopic, 'user' => $w->member]);
    $secretForum = $w->secret;
    foreach ([
        'Thema' => [['topic_id' => 999999], ['topic_id' => $w->secretTopic]],
        'Forum' => [['forum_id' => 999999], ['forum_id' => $secretForum]],
        'Beitrag' => [['post_id' => 999999], ['post_id' => $secretPost]],
    ] as $kind => [$missing, $invisible]) {
        $a = $w->render->preview($w->member, 'Probe', $missing);
        $b = $w->render->preview($w->member, 'Probe', $invisible);
        T::same([false, 'not_found'], [$a['success'] ?? null, $a['error'] ?? null], "404-Regel {$kind}: fehlendes Ziel ⇒ not_found");
        T::same($a, $b, "404-Regel {$kind}: unsichtbares Ziel ergibt dasselbe Ergebnis wie ein fehlendes", json_encode([$a, $b]));
    }
    $foreignResult = $w->render->preview($w->member, '![b](attachment:6)', ['post_id' => $foreign]);
    T::check(in_array($foreignResult['error'] ?? null, ['not_found', 'no_permission'], true), 'fremder Beitrag bei edit ⇒ not_found bzw. no_permission', json_encode($foreignResult));
    T::same([[], 0], [$w->throttleLog, $w->purifies], 'Abgelehnte lösen weder Drossel noch Parsen aus');

    $w->canLog = [];
    $w->clockReads = 0;
    $w->db->log = [];
    $result = $w->render->preview($w->member, "**Vorschau** mit ![b](attachment:5)", ['topic_id' => $w->topic]);
    T::same(true, $result['success'] ?? null, 'Antwort-Vorschau erlaubt', json_encode($result));
    T::same(['user' => $w->member, 'action' => 'reply', 'context' => ['topic_id' => $w->topic, 'now' => $w->now]], $w->canLog[0] ?? null, 'Rechteprüfung: Schreibaktion am Ziel mit now, ohne content');
    T::same([['preview', $w->member]], $w->throttleLog, 'Drossel preview nach erlaubter Rechteprüfung');
    T::same(1, $w->clockReads, 'Uhr einmal');
    T::check(!str_contains((string) ($result['html'] ?? ''), '<img'), 'Antwort: ein Anhang eines Beitrags wird nicht eingebettet (post_id nur bei edit)', (string) ($result['html'] ?? ''));
    T::same(['images' => 1, 'attachments' => [5]], array_intersect_key($result['stats'] ?? [], ['images' => 0, 'attachments' => 0]), 'Zählung der Vorschau');
    T::same([], $w->logged(CR_WRITE_SQL), 'Vorschau schreibt nichts');
    T::same(0, $w->filterLog[0]['post_id'] ?? null, 'Filter mit postId 0');
    $edit = $w->render->preview($w->member, "Bearbeitet ![b](attachment:5)", ['post_id' => $own]);
    T::check(str_contains((string) ($edit['html'] ?? ''), '<img'), 'Bearbeiten des eigenen Beitrags: dessen Anhang wird eingebettet (post_id)', json_encode($edit));
    $w->canLog = [];
    T::same(true, $w->render->preview($w->member, 'Neues Thema', ['forum_id' => $w->open])['success'] ?? null, 'Vorschau eines neuen Themas erlaubt');
    T::same(['create_topic', ['forum_id' => $w->open, 'now' => $w->now]], [$w->canLog[0]['action'] ?? null, $w->canLog[0]['context'] ?? null], 'forum_id ⇒ create_topic am Forum');
    $w->canLog = [];
    $w->render->preview($w->member, 'Bearbeitet', ['post_id' => $own]);
    T::same(['edit', ['post_id' => $own, 'now' => $w->now]], [$w->canLog[0]['action'] ?? null, $w->canLog[0]['context'] ?? null], 'post_id ⇒ edit am Beitrag');

    // Grenzgründe gelten als erlaubt, andere Gründe nicht.
    foreach (TopicService::LIMIT_REASONS as $reason) {
        $w->rule = static fn (?int $u, string $a, array $c): ?AccessDecision => $a === 'reply' ? AccessDecision::deny($reason, 30) : null;
        T::same(true, $w->render->preview($w->member, 'Trotz Grenze', ['topic_id' => $w->topic])['success'] ?? null, 'Grenzgrund ' . $reason . ': Vorschau erlaubt');
    }
    $w->rule = static fn (?int $u, string $a, array $c): ?AccessDecision => $a === 'reply' ? AccessDecision::deny('topic_locked') : null;
    T::same('topic_locked', $w->render->preview($w->member, 'Gesperrtes Thema', ['topic_id' => $w->topic])['error'] ?? null, 'anderer Grund: abgelehnt mit dem Grund');
    $w->rule = null;

    // Drossel: 10 je 60 s (der Dienst drosselt selbst).
    $w->throttleLog = [];
    $w->purifies = 0;
    $w->throttle = ['allowed' => false, 'retry_after' => 60];
    T::same(['success' => false, 'error' => 'rate_limited', 'retry_after' => 60], $w->render->preview($w->member, 'Zu oft', ['topic_id' => $w->topic]), 'Drossel abgelehnt ⇒ rate_limited mit retry_after');
    T::same([[['preview', $w->member]], 0], [$w->throttleLog, $w->purifies], 'Drossel abgelehnt: kein Parsen');
    $w->throttle = ['allowed' => true, 'retry_after' => 0];

    // Zitate in der Vorschau: wie present(), Betrachter = Schreibender.
    $secret = $w->post('Verborgen', ['topic' => $w->secretTopic]);
    $visible = $w->post('Sichtbar');
    $quoted = $w->render->preview($w->member, crQuote($secret, 'GEHEIM') . "\n\n" . crQuote($visible, 'LESBAR') . "\n\nAntwort", ['topic_id' => $w->topic]);
    T::check(!str_contains((string) ($quoted['html'] ?? 'GEHEIM'), 'GEHEIM') && str_contains((string) ($quoted['html'] ?? ''), 'LESBAR') && str_contains((string) ($quoted['html'] ?? ''), 'cms-quote__source'), 'Vorschau: Zitate je Recht des Schreibenden aufgelöst', (string) ($quoted['html'] ?? ''));
    $w->purifierMissing = true;
    $plain = $w->render->preview($w->member, crQuote($secret, 'GEHEIM') . "\n\nAntwort", ['topic_id' => $w->topic]);
    T::check(str_starts_with((string) ($plain['html'] ?? ''), '<p class="cms-plain">') && !str_contains((string) ($plain['html'] ?? 'GEHEIM'), 'GEHEIM'), 'Vorschau ohne Purifier: neutralisierter Klartext', (string) ($plain['html'] ?? ''));
    $w->purifierMissing = false;
    $big = new CrWorld(['post_max' => 50000]);
    T::same('render_failed', $big->render->preview($big->member, str_repeat('[', 25000), ['topic_id' => $big->topic])['error'] ?? null, 'Grenze überschritten (25.000 Begrenzer) ⇒ render_failed');
});

T::run('G Schreib-Rendern und Zählung delegieren an ContentRenderer; Konstruktor ohne Datenbank', static function (): void {
    $w = new CrWorld();
    T::same("<p><strong>x</strong></p>\n", $w->render->render('**x**', ['post_id' => null, 'uploader_id' => $w->member])['html'] ?? null, 'render(): HTML des Renderers');
    T::same(['ok' => false, 'error' => 'render_failed'], array_intersect_key($w->render->render("\xFF"), ['ok' => 0, 'error' => 0]), 'render(): ungültiges UTF-8 ⇒ render_failed');
    $analysis = $w->render->analyze('[a](https://example.org) ![b](https://bild.example/x.png)');
    T::same([true, 1], [$analysis['ok'], $analysis['stats']['images']], 'analyze(): Zählung ohne HTML');
    T::check($analysis['stats']['external_links'] >= 1, 'analyze(): externer Link gezählt');
    T::same(['ok' => false, 'error' => 'render_failed'], array_intersect_key($w->render->analyze("\xFF"), ['ok' => 0, 'error' => 0]), 'analyze(): render_failed');
    $db = CommunityTestDatabase::create();
    $db->log = [];
    new RenderService($db, Settings::fromArray([]), static fn (): int => 0, static fn (?int $u, string $a, array $c): AccessDecision => AccessDecision::allow());
    T::same([], $db->log, 'Konstruktor: keine Abfrage');
    T::same(['create_topic', 'reply', 'edit'], array_values(RenderService::PREVIEW_ACTIONS), 'PREVIEW_ACTIONS: forum_id ⇒ create_topic, topic_id ⇒ reply, post_id ⇒ edit');
    T::same([5, 50000, 200, 20, 200000], [RenderService::LAZY_GUEST_POSTS, RenderService::LAZY_GUEST_CHARS, RenderService::MAX_QUOTE_LOOKUPS, RenderService::LAZY_MAX_POSTS, RenderService::LAZY_MAX_CHARS], 'Grenzen laut Vertrag §3.6');
});

// Teildateien weiterer Besitzer (T: part-services.php in W3; Vertrag §1 Nr. 6).
foreach (glob(__DIR__ . '/part-*.php') ?: [] as $part) {
    require $part;
}

T::finish();
