<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;
define('ABSPATH', $root . 'CMS' . DIRECTORY_SEPARATOR);
require ABSPATH . 'core/Http/InlineStyleRewriter.php';

use CMS\Http\InlineStyleRewriter;

/** @throws RuntimeException */
function cspStyleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function cspStyleRewrite(string $html): string
{
    return (new InlineStyleRewriter('NONCE123'))->rewrite($html);
}

function cspStyleClassFor(string $declarations): string
{
    return 'cms-s-' . substr(hash('sha256', $declarations), 0, 12);
}

// 1) Grundfall: style → Klasse + nonce-Style-Block vor </head>
$out = cspStyleRewrite('<!doctype html><html><head><title>T</title></head><body><div style="color:red; margin:0">x</div></body></html>');
$class = cspStyleClassFor('color:red; margin:0;');
cspStyleAssert(str_contains($out, '<div class="' . $class . '">x</div>'), 'Style-Attribut wurde nicht in eine Klasse umgewandelt: ' . $out);
cspStyleAssert(str_contains($out, '<style id="cms-inline-styles" nonce="NONCE123">.' . $class . '.' . $class . '.' . $class . '{color:red; margin:0;}</style></head>'), 'Nonce-Style-Block fehlt oder steht nicht vor </head>.');
cspStyleAssert(!str_contains($out, 'style="'), 'Style-Attribut ist nach dem Rewrite noch vorhanden.');

// 2) Bestehende Klasse wird ergänzt, andere Attribute bleiben unverändert
$out = cspStyleRewrite('<html><head></head><body><a href="/x?a=1&amp;b=2" class="btn  btn-primary" style="display:none" data-x=\'y\' disabled>l</a></body></html>');
$class = cspStyleClassFor('display:none;');
cspStyleAssert(str_contains($out, '<a href="/x?a=1&amp;b=2" class="btn  btn-primary ' . $class . '" data-x=\'y\' disabled>l</a>'), 'Klassen-Merge oder Attributerhalt fehlerhaft: ' . $out);

// 3) script/style/textarea/Kommentare bleiben unangetastet
$src = '<html><head><style>.a{color:red}</style></head><body>'
    . '<script>var t = \'<div style="color:blue">\';</script>'
    . '<textarea><p style="color:green">roh</p></textarea>'
    . '<!-- <span style="color:pink"> -->'
    . '<script type="application/json">{"html":"<b style=\\"x:y\\">"}</script>'
    . '</body></html>';
cspStyleAssert(cspStyleRewrite($src) === $src, 'Raw-Text-Elemente oder Kommentare wurden verändert.');

// 4) "style=" innerhalb eines anderen Attributwerts ist kein Style-Attribut
$src = '<html><head></head><body><span title="a style=&quot;color:red&quot;">t</span></body></html>';
cspStyleAssert(cspStyleRewrite($src) === $src, 'style= innerhalb eines Attributwerts wurde fälschlich umgeschrieben.');

// 5) Unsichere Werte (Regelausbruch) bleiben unverändert und damit CSP-blockiert
foreach ([
    'color:red}body{background:red',
    'color:red;</style><script>alert(1)</script>',
    '@import url(x)',
    'color:red/* x */',
    'background:\\75rl(x)',
    'nur-text-ohne-doppelpunkt',
    'font-family:"Open',
    "font-family:'Open",
    'background:url(x',
    'width:calc(100% - 2px))',
    'grid-template-columns:[a b',
    "content:\"a\nb\"",
] as $unsafe) {
    $src = '<html><head></head><body><div style="' . htmlspecialchars($unsafe, ENT_QUOTES) . '">u</div></body></html>';
    cspStyleAssert(cspStyleRewrite($src) === $src, 'Unsicherer Style-Wert wurde übernommen: ' . $unsafe);
}

// 5b) Balancierte Quotes/Klammern werden weiter umgeschrieben; ein defekter Wert beeinflusst nachfolgende Regeln nicht
$out = cspStyleRewrite('<html><head></head><body><i style="font-family:&quot;Open;">a</i><b style="width:calc(100% - (2px * 2));background:url(\'a(1).png\')">b</b><u style="color:red">c</u></body></html>');
cspStyleAssert(str_contains($out, 'style="font-family:&quot;Open;"'), 'Defekter Style-Wert wurde umgeschrieben: ' . $out);
cspStyleAssert(str_contains($out, '{width:calc(100% - (2px * 2));background:url(\'a(1).png\');}') && str_contains($out, '{color:red;}'), 'Balancierte Werte wurden nicht korrekt umgeschrieben: ' . $out);

// 6) Leeres style-Attribut wird entfernt, ohne Style-Block zu erzeugen
$out = cspStyleRewrite('<html><head></head><body><p style="  ">e</p></body></html>');
cspStyleAssert($out === '<html><head></head><body><p>e</p></body></html>', 'Leeres style-Attribut wurde nicht entfernt: ' . $out);

// 7) Entities werden dekodiert, identische Deklarationen teilen sich eine Regel
$out = cspStyleRewrite('<html><head></head><body><i style="font-family:&quot;Inter&quot;">a</i><b style="font-family:&quot;Inter&quot;">b</b></body></html>');
$class = cspStyleClassFor('font-family:"Inter";');
cspStyleAssert(substr_count($out, '.' . $class . '.' . $class) === 1 && substr_count($out, 'class="' . $class . '"') === 2, 'Entity-Dekodierung oder Regel-Deduplizierung fehlerhaft: ' . $out);

// 8) Fragmente ohne </head> (AJAX-Partials) bleiben unverändert
$src = '<div style="color:red">frag</div>';
cspStyleAssert(cspStyleRewrite($src) === $src, 'HTML-Fragment ohne </head> wurde verändert.');

// 9) Void-Tags, Großschreibung und SVG
$out = cspStyleRewrite('<html><head></head><body><IMG SRC="a.png" STYLE="width:10px"/><svg><rect style="fill:#000"/></svg></body></html>');
cspStyleAssert(str_contains($out, '<IMG SRC="a.png" class="' . cspStyleClassFor('width:10px;') . '"/>'), 'Void-Tag mit Großschreibung falsch umgeschrieben: ' . $out);
cspStyleAssert(str_contains($out, '<rect class="' . cspStyleClassFor('fill:#000;') . '"/>'), 'SVG-Element falsch umgeschrieben: ' . $out);

// 10) Ungültiges Markup bricht nicht und bleibt erhalten
$src = '<html><head></head><body>a < b <div class="x style=">z</div><p style=\'color:red\'>q</p></body></html>';
$out = cspStyleRewrite($src);
cspStyleAssert(str_contains($out, 'a < b ') && str_contains($out, '<div class="x style=">z</div>') && str_contains($out, 'class="' . cspStyleClassFor('color:red;') . '"'), 'Ungültiges Markup wurde nicht robust verarbeitet: ' . $out);

// 11) Output-Callback: Teilausgaben werden durchgereicht
$rewriter = new InlineStyleRewriter('N');
$partial = '<html><head></head><body><div style="color:red">';
cspStyleAssert($rewriter->handle($partial, PHP_OUTPUT_HANDLER_START) === $partial, 'Teilausgabe wurde transformiert.');
cspStyleAssert($rewriter->handle('</div></body></html>', PHP_OUTPUT_HANDLER_FINAL) === '</div></body></html>', 'Nach einer Teilausgabe wurde nicht durchgereicht.');

echo "CSP inline style rewriter tests passed.\n";
