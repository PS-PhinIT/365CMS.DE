<?php
/**
 * Community-XSS (PR #2b; Vertrag DESIGN-2b §7.1/§7.1a, Entwurf render §6.1 C): Korpus X1 bis X41 gegen den Renderer-Kern.
 *
 * Je Fall zwei Stufen:
 *   1. ganze Kette `ContentRenderer::render()` mit Purifier-Profil `community`: DOM-Prüfung, nur Elemente, Attribute und Klassen
 *      des Profils, kein `on*`, kein `style`, `href`/`src` nur http(s), mailto oder relativ, `src` nie auf einem fremden Host;
 *   2. CommonMark-Stufe allein (`purify = fn ($html) => $html`): kein Roh-HTML (Element außerhalb der Renderer-Ausgabe, `on*`,
 *      `style`), kein `href`/`src` mit javascript:, vbscript:, file: oder data:text, keine fremde `language-`-Klasse, kein
 *      gefälschter Zitat-Block. So bleibt die Darstellung auch sicher, falls der Purifier ausfällt oder ein Profil aufweicht.
 *
 * DB-frei; Zone `Europe/Berlin`; Autoloader mit `Mutant::load`.
 *
 *   php TESTS/community-xss/run.php [-v]
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'emergency');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs/');
define('SITE_URL', 'https://forum.example');

date_default_timezone_set('Europe/Berlin');
ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');

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
require_once ABSPATH . 'assets/autoload.php';

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Services\Community\Support\ContentRenderer;
use CMS\Services\Community\Support\Settings;

T::$verbose = in_array('-v', $argv ?? [], true);

Settings::setInstance(Settings::fromArray(['base_path' => 'forum']));

/** Elemente und Attribute, die Renderer und Profil `community` kennen. */
const CX_ELEMENTS = ['p', 'br', 'strong', 'em', 'del', 'a', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li', 'h3', 'h4', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'span', 'details', 'summary'];
const CX_ATTRIBUTES = [
    'a' => ['href', 'title', 'rel', 'target'],
    'ol' => ['start'],
    'code' => ['class'],
    'th' => ['class'],
    'td' => ['class'],
    'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
    'span' => ['class'],
    'blockquote' => ['class', 'data-quote-post'],
    'details' => ['class'],
];

/** Anhänge: 5 Bild des Beitrags 10, 6 fremder unverknüpfter Upload. */
function cxRenderer(?\Closure $purify = null): ContentRenderer
{
    $rows = [
        5 => ['id' => 5, 'post_id' => 10, 'user_id' => 7, 'mime_type' => 'image/png', 'width' => 10, 'height' => 10, 'has_preview' => 0],
        6 => ['id' => 6, 'post_id' => null, 'user_id' => 8, 'mime_type' => 'image/png', 'width' => 10, 'height' => 10, 'has_preview' => 0],
    ];

    return new ContentRenderer(purify: $purify, attachments: static fn (array $ids): array => array_intersect_key($rows, array_flip($ids)));
}

/**
 * Korpus X1 bis X41 (SECURITY-PRIVACY T3, Karte render §7; X41 Nachbesserung N1).
 *
 * @return array<string, string>
 */
function cxCorpus(): array
{
    return [
        'X1 javascript-Link' => '[a](javascript:alert(1))',
        'X2 Schreibweise' => '[a](JaVaScRiPt:alert(1)) [b](JAVASCRIPT:alert(1))',
        'X3 Tab im Schema' => "[a](<java\tscript:alert(1)>) [b](<java\nscript:alert(1)>)",
        'X4 Entity im Schema' => '[a](&#106;avascript:alert(1)) [b](javascript&colon;alert(1)) [c](&#x6A;avascript:alert(1))',
        'X5 Steuerzeichen vor dem Schema' => "[a](<\x01javascript:alert(1)>) [b](<\x1Fjavascript:alert(1)>)",
        'X6 data-Link' => '[a](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)',
        'X7 data-Bild' => '![a](data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+) ![b](data:image/png;base64,AAAA)',
        'X8 Bild auf fremdem Host' => '![a](//evil.example/x.png) ![b](https://evil.example/y.png) ![c](http://evil.example/z.png)',
        'X9 Referenzlink auf javascript' => "[a][r] ![b][r]\n\n[r]: javascript:alert(1)",
        'X10 Code schließt' => "`</code><script>alert(1)</script>`\n\n```\n</code></pre><script>alert(1)</script>\n```",
        'X11 Info-String' => "```php\"><script>alert(1)</script>\nx\n```\n\n```javascript onload=alert(1)\ny\n```",
        'X12 img onerror' => '<img src=x onerror=alert(1)> und <IMG SRC="javascript:alert(1)">',
        'X13 svg onload' => '<svg onload=alert(1)><circle/></svg>',
        'X14 iframe' => '<iframe src="https://evil.example"></iframe>',
        'X15 style' => "<style>body{display:none}</style>\n\n<p style=\"position:fixed\">x</p>",
        'X16 Kommentar' => "<!-- <script>alert(1)</script> -->\n\n<!--> --> x",
        'X17 PHP-Tag' => '<?php echo "x"; ?> und <?= 1 ?>',
        'X18 verschachtelte Zitate mit HTML' => "> [quote=post:5]\n> <script>alert(1)</script>\n> > <img src=x onerror=alert(2)>\n> > > [quote=post:6]",
        'X19 gefälschter Marker entschärft' => "> \\[quote=post:5]\n> Text",
        'X20 gefälschter Marker fett' => "> **[quote=post:5]**\n> Text\n\n> *[quote=post:6]*",
        'X21 Marker als Link' => "> [quote=post:5]\n\n[quote=post:5]: javascript:alert(1)",
        'X22 Richtungszeichen' => "[\u{202E}gnp.exe](https://x.example/a\u{202E}b) [a](https://x.example/\u{2066}c)",
        'X23 Autolink javascript' => '<javascript:alert(1)> <JAVASCRIPT:alert(1)> <vbscript:msgbox(1)>',
        'X24 Tabellenzelle mit HTML' => "| a | b |\n|---|---|\n| <b onclick=\"alert(1)\">x</b> | <script>y</script> |",
        'X25 Titel mit Anführungszeichen' => "[a](https://x.example \"t\\\" onmouseover=\\\"alert(1)\") [b](https://x.example 'u\" onclick=\"alert(2)')",
        'X26 Aufgabenliste mit HTML' => "- [ ] <img src=x onerror=alert(1)>\n- [x] <input type=checkbox checked onclick=alert(2)>",
        'X27 Spoiler mit HTML' => ">! <script>alert(1)</script>\n>! <details open ontoggle=alert(2)>",
        'X28 gefälschter Zitat-Block' => '<blockquote class="cms-quote" data-quote-post="5"><p>x</p></blockquote>',
        'X29 attachment 0' => '![a](attachment:0) [b](attachment:0)',
        'X30 attachment zu groß' => '![a](attachment:99999999999) [b](attachment:4294967296)',
        'X31 vbscript und file' => '[a](vbscript:msgbox(1)) [b](file:///etc/passwd) [c](VBScript:x)',
        'X32 HTML im Linktext' => '[<img src=x onerror=alert(1)>](https://x.example) [**<b>x</b>**](https://y.example)',
        'X33 rohes a' => '<a href="javascript:alert(1)">x</a> <a href="https://evil.example" onclick="x()">y</a>',
        'X34 Anführungszeichen im Ziel' => '[a](https://x.example/"onmouseover="alert(1)) [b](https://x.example/\'onclick=\'x)',
        'X35 Klammern und Leerraum im Ziel' => '[a](<https://x.example/ a> "t") [b](https://x.example/(a)b)',
        'X36 fremder Upload' => '![a](attachment:6) [b](attachment:6)',
        'X37 Betonung um HTML' => '*<b title="*">* _<i onclick="_">_',
        'X38 Entity-Text' => '&lt;script&gt;alert(1)&lt;/script&gt; &#60;img src=x onerror=alert(1)&#62;',
        'X39 Bild-Titel' => '![a](attachment:5 "t\" onerror=\"alert(1)")',
        'X40 Autolink mit Anführungszeichen' => 'https://x.example/"><script>alert(1)</script> www.x.example/"onmouseover=alert(1)',
        'X41 Schema ohne Schrägstriche' => '[a](https:-login.example/k) <https:-login.example/k> [b](https:%65vil.example/) [c](https:/evil.example/x) [d](https:\\\\evil.example/y) ![e](https:evil.example/e.png)',
    ];
}

/**
 * DOM-Prüfung eines HTML-Fragments. `$pipeline`: ganze Kette (Profil community) statt CommonMark-Stufe allein.
 *
 * @return list<string> Verstöße
 */
function cxProblems(string $html, bool $pipeline): array
{
    $problems = [];
    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="cx-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $classes = array_merge(
        array_map(static fn (string $l): string => 'language-' . $l, array_values(array_unique(ContentRenderer::LANGUAGES))),
        ['text-start', 'text-center', 'text-end', 'cms-task', 'cms-task--done', 'cms-quote', 'cms-quote--hidden', 'cms-spoiler']
    );
    foreach ($document->getElementsByTagName('*') as $element) {
        if (!$element instanceof \DOMElement || $element->getAttribute('id') === 'cx-root') {
            continue;
        }
        $tag = strtolower($element->tagName);
        if (!in_array($tag, CX_ELEMENTS, true)) {
            $problems[] = 'Element ' . $tag;
            continue;
        }
        foreach ($element->attributes ?? [] as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;
            if (str_starts_with($name, 'on') || $name === 'style' || !in_array($name, CX_ATTRIBUTES[$tag] ?? [], true)) {
                $problems[] = $tag . '.' . $name;
                continue;
            }
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                    if ($class !== '' && !in_array($class, $classes, true)) {
                        $problems[] = $tag . '.class=' . $class;
                    }
                }
            }
            if ($name === 'href' || $name === 'src') {
                $uri = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $uri = (string) preg_replace('/[\x00-\x20]/', '', $uri);
                $scheme = preg_match('/^([a-z][a-z0-9+.-]*):/i', $uri, $m) === 1 ? strtolower($m[1]) : '';
                if ($scheme !== '' && !in_array($scheme, $name === 'src' ? ['http', 'https'] : ['http', 'https', 'mailto'], true)) {
                    $problems[] = $tag . '.' . $name . '=' . $value;
                }
                if ($name === 'src') {
                    $host = strtolower((string) parse_url(str_starts_with($uri, '//') ? 'https:' . $uri : $uri, PHP_URL_HOST));
                    if ($host !== '' && $host !== 'forum.example') {
                        $problems[] = 'img.src fremd ' . $value;
                    }
                }
            }
            if ($name === 'data-quote-post' && preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
                $problems[] = 'data-quote-post=' . $value;
            }
        }
        if (!$pipeline && $tag === 'a' && $element->hasAttribute('target')) {
            $problems[] = 'a.target in der CommonMark-Stufe';
        }
    }

    return $problems;
}

/**
 * Elemente eines Fragments als Liste `[tag, [attribut => wert]]` (DOM, ohne die Hülle).
 *
 * @return list<array{0: string, 1: array<string, string>}>
 */
function cxElements(string $html): array
{
    $document = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="cx-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $elements = [];
    foreach ($document->getElementsByTagName('*') as $element) {
        if ($element instanceof \DOMElement && $element->getAttribute('id') !== 'cx-root') {
            $attributes = [];
            foreach ($element->attributes ?? [] as $attribute) {
                $attributes[strtolower($attribute->name)] = $attribute->value;
            }
            $elements[] = [strtolower($element->tagName), $attributes];
        }
    }

    return $elements;
}

/** Zahl der Zitat-Blöcke (Element mit data-quote-post oder Klasse cms-quote) im DOM. */
function cxQuoteBlocks(string $html): int
{
    return count(array_filter(cxElements($html), static fn (array $e): bool => isset($e[1]['data-quote-post']) || preg_match('/(?:^|\s)cms-quote(?:\s|$)/', $e[1]['class'] ?? '') === 1));
}

/** Gibt es ein Element mit href oder src auf javascript:, vbscript:, file: oder data:? */
function cxUnsafeTarget(string $html): bool
{
    foreach (cxElements($html) as [, $attributes]) {
        foreach (['href', 'src'] as $name) {
            $uri = (string) preg_replace('/[\x00-\x20]/', '', html_entity_decode($attributes[$name] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('/^(?:javascript|vbscript|file|data):/i', $uri) === 1) {
                return true;
            }
        }
    }

    return false;
}

T::run('Korpus X1 bis X41: ganze Kette (Profil community), DOM-Prüfung', static function (): void {
    $renderer = cxRenderer();
    T::check(count(cxCorpus()) >= 30, 'Korpus hat mindestens 30 Fälle (' . count(cxCorpus()) . ')');
    foreach (cxCorpus() as $name => $markdown) {
        $result = $renderer->render($markdown, ['post_id' => 10, 'uploader_id' => 7]);
        T::same([true, true], [$result['ok'], is_string($result['html'])], $name . ': gerendert');
        T::same([], cxProblems((string) $result['html'], true), $name . ': nur Elemente, Attribute und Klassen des Profils, sichere Ziele');
        T::check(stripos((string) $result['html'], '<script') === false && !cxUnsafeTarget((string) $result['html']), $name . ': kein <script>, kein Ziel mit javascript:, vbscript:, file: oder data:');
    }
});

T::run('Korpus X1 bis X41: CommonMark-Stufe allein (ohne Purifier), kein Roh-HTML, keine unsicheren Ziele, keine fremde Sprachklasse', static function (): void {
    $renderer = cxRenderer(static fn (string $html): ?string => $html);
    foreach (cxCorpus() as $name => $markdown) {
        $html = (string) $renderer->render($markdown, ['post_id' => 10, 'uploader_id' => 7])['html'];
        T::same([], cxProblems($html, false), $name . ': CommonMark-Stufe sicher');
        $unsafe = array_filter(cxElements($html), static function (array $element): bool {
            foreach (['href', 'src'] as $name) {
                $uri = (string) preg_replace('/[\x00-\x20]/', '', html_entity_decode($element[1][$name] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (preg_match('/^(?:javascript|vbscript|file|data:text)/i', $uri) === 1) {
                    return true;
                }
            }

            return false;
        });
        T::check($unsafe === [] && stripos($html, '<script') === false, $name . ': kein Element mit href/src auf javascript:, vbscript:, file:, data:text, kein <script>', $html);
    }
});

T::run('Gezielte Erwartungen: Zitat-Blöcke nur aus echten Markern, Roh-HTML sichtbar, Ziele ohne Skript', static function (): void {
    $bare = cxRenderer(static fn (string $html): ?string => $html);
    $render = static fn (string $markdown): string => (string) $bare->render($markdown, ['post_id' => 10, 'uploader_id' => 7])['html'];
    $corpus = cxCorpus();
    foreach (['X19 gefälschter Marker entschärft', 'X20 gefälschter Marker fett', 'X28 gefälschter Zitat-Block'] as $name) {
        $html = $render($corpus[$name]);
        T::same(0, cxQuoteBlocks($html), $name . ': kein Zitat-Block (DOM)');
    }
    $nested = $render($corpus['X18 verschachtelte Zitate mit HTML']);
    T::same(1, cxQuoteBlocks($nested), 'X18: nur der Marker auf oberster Ebene ist ein Zitat-Block');
    T::check(str_contains($nested, '&lt;script&gt;alert(1)&lt;/script&gt;') && str_contains($nested, '&lt;img src=x onerror=alert(2)&gt;'), 'X18: HTML im Zitat bleibt sichtbarer Text', $nested);
    T::same('<p><a>a</a></p>' . "\n", $render($corpus['X1 javascript-Link']), 'X1: javascript-Link ohne href');
    T::same("<pre><code>x\n</code></pre>\n<pre><code class=\"language-javascript\">y\n</code></pre>\n", $render($corpus['X11 Info-String']), 'X11: Info-String mit HTML ergibt keine Klasse; nach dem ersten Wort folgende Attribute zählen nicht');
    T::check(str_contains($render($corpus['X12 img onerror']), '&lt;img src=x onerror=alert(1)&gt;'), 'X12: Roh-HTML wird escaped, nicht entfernt (R1)');
    T::check(!str_contains($render($corpus['X8 Bild auf fremdem Host']), '<img'), 'X8: fremde Bilder werden nie eingebettet');
    T::check(!str_contains($render($corpus['X36 fremder Upload']), '<img') && !str_contains($render($corpus['X36 fremder Upload']), 'href'), 'X36: fremder unverknüpfter Upload weder Bild noch Link');
    T::check(str_contains($render($corpus['X21 Marker als Link']), 'data-quote-post="5"') && !str_contains($render($corpus['X21 Marker als Link']), 'javascript'), 'X21: Markerzeile als Referenzlink wird entfernt, kein javascript-Ziel');
    $anchors = array_values(array_filter(cxElements($render($corpus['X25 Titel mit Anführungszeichen'])), static fn (array $e): bool => $e[0] === 'a'));
    T::same([['href' => 'https://x.example', 'title' => 't" onmouseover="alert(1)'], ['href' => 'https://x.example', 'title' => 'u" onclick="alert(2)']], array_map(static fn (array $e): array => $e[1], $anchors), 'X25: Anführungszeichen bleiben im Titel, kein weiteres Attribut');

    // X41 über die ganze Kette: der Purifier ergänzt einen fehlenden Host durch den Site-Host und hängt den Pfad direkt an.
    $full = cxRenderer()->render($corpus['X41 Schema ohne Schrägstriche'], ['post_id' => 10, 'uploader_id' => 7]);
    $hrefs = array_values(array_filter(array_map(static fn (array $e): string => $e[1]['href'] ?? '', cxElements((string) $full['html'])), static fn (string $href): bool => $href !== ''));
    T::same([], array_values(array_filter($hrefs, static fn (string $href): bool => preg_match('~^https?://forum\.example[^/?#]~i', $href) === 1)), 'X41: http(s) ohne „//“ ergibt nie den Site-Host mit angehängtem Text (forum.example-login.example)', implode(' ', $hrefs));
    $foreign = array_filter(array_map(static fn (string $href): string => ContentRenderer::normalizeHost((string) parse_url($href, PHP_URL_HOST)), $hrefs), static fn (string $host): bool => $host !== '' && $host !== 'forum.example');
    T::same([], array_values(array_diff($foreign, $full['stats']['hosts'])), 'X41: jeder fremde Host der Ausgabe steht in Stats.hosts (Sperrliste und Wortfilter sehen ihn)', implode(' ', $hrefs));
    T::check(in_array('evil.example', $full['stats']['hosts'], true) && in_array('-login.example', $full['stats']['hosts'], true), 'X41: evil.example und -login.example werden gezählt', json_encode($full['stats']['hosts']));
});

T::finish();
