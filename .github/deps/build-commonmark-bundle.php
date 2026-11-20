<?php
/**
 * Build-Skript des CommonMark-Bündels (PR #2b, Vertrag DESIGN-2b §2.1 Nr. 1 und 2, Entwurf render §9).
 *
 * Baut aus einem Composer-Vendor-Verzeichnis eine einzige PHP-Datei mit geklammerten namespace-Blöcken (league/commonmark,
 * league/config, dflydev/dot-access-data, nette/schema, nette/utils; nur die transitiv benötigten Dateien, Namensräume unter
 * `--prefix`) und die Lizenzdatei. Deterministisch: gleiche Eingabe ergibt dieselben Bytes.
 *
 * Aufruf (aus dem Repository-Wurzelverzeichnis; Ablauf in DOC/assets/commonmark/README.md):
 *   php .github/deps/build-commonmark-bundle.php --vendor=<vendor> --lock=.github/deps/composer.lock \
 *       --out=CMS/assets/commonmark/commonmark.php --license=CMS/assets/commonmark/LICENSE --prefix='CMS\Vendor' --guard \
 *       --roots-from=CMS/core/Services/Community/Support/ContentRenderer.php [--verify] [--keep-comments] [--list=<datei>]
 *
 * Vor dem Bau: jedes Paket in <vendor>/composer/installed.json hat die Version und dist-Referenz aus --lock (sonst Abbruch),
 * jede Quelle hat `declare(strict_types=1)`, kein `?>`, keine geklammerten Namespaces, keine Klassennamen als Zeichenkette.
 *
 * `--verify` schreibt nichts: es baut im Speicher und vergleicht Byte für Byte mit --out und --license (Exit 0 gleich,
 * Exit 1 abweichend). So lässt sich prüfen, dass das ausgelieferte Bündel aus der Lockdatei reproduzierbar ist.
 */

declare(strict_types=1);

const PACKAGES = [
    // Paket => [Quellordner relativ zu vendor/<paket>/, Lizenzdatei]
    'league/commonmark'        => ['src', 'LICENSE'],
    'league/config'            => ['src', 'LICENSE.md'],
    'dflydev/dot-access-data'  => ['src', 'LICENSE'],
    'nette/schema'             => ['src', 'license.md'],
    'nette/utils'              => ['src', 'license.md'],
];

/** Klassen, die der ContentRenderer direkt benutzt (Wurzeln der Hülle). */
const ROOTS = [
    'League\\CommonMark\\MarkdownConverter',
    'League\\CommonMark\\Environment\\Environment',
    'League\\CommonMark\\Extension\\CommonMark\\CommonMarkCoreExtension',
    'League\\CommonMark\\Extension\\Table\\TableExtension',
    'League\\CommonMark\\Extension\\Strikethrough\\StrikethroughExtension',
    'League\\CommonMark\\Extension\\Autolink\\AutolinkExtension',
    'League\\CommonMark\\Extension\\TaskList\\TaskListExtension',
    'League\\CommonMark\\Event\\DocumentParsedEvent',
    'League\\CommonMark\\Exception\\UnexpectedEncodingException',
    'League\\CommonMark\\Exception\\CommonMarkException',
    'League\\Config\\Exception\\ValidationException',
    'League\\CommonMark\\Util\\RegexHelper',
    'League\\CommonMark\\Util\\HtmlElement',
    'League\\CommonMark\\Util\\Xml',
];

/** Zusätzliche Reihenfolge-Abhängigkeiten, die extends/implements nicht zeigen (class_alias zur Ladezeit). */
const EXTRA_DEPS = [
    'nette/utils/src/compatibility.php' => ['Nette\\HtmlStringable', 'Nette\\Localization\\Translator'],
];

/** Präfixe, die das Bündel nicht enthalten darf (liegen bereits unter CMS/assets/ und werden dort per PSR-4 geladen). */
const EXTERNAL_PREFIXES = ['Psr\\'];

function fail(string $msg): never
{
    fwrite(STDERR, "FEHLER: {$msg}\n");
    exit(1);
}

$opts = getopt('', ['vendor:', 'lock:', 'out:', 'license:', 'keep-comments', 'guard', 'list:', 'prefix:', 'roots-from:', 'verify']);
$vendor = rtrim((string) ($opts['vendor'] ?? ''), '/');
$lockFile = (string) ($opts['lock'] ?? '');
$out = (string) ($opts['out'] ?? '');
$licenseOut = (string) ($opts['license'] ?? '');
$keepComments = isset($opts['keep-comments']);
$guard = isset($opts['guard']);
$verify = isset($opts['verify']);
$prefix = trim((string) ($opts['prefix'] ?? ''), '\\');
if ($prefix !== '' && preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)*$/', $prefix) !== 1) {
    fail('ungültiges --prefix');
}
if ($vendor === '' || $lockFile === '' || $out === '' || $licenseOut === '') {
    fail('Aufruf: --vendor= --lock= --out= --license= [--prefix=] [--guard] [--roots-from=] [--verify] [--keep-comments] [--list=]');
}

// ── 1. Versionen gegen die Lockdatei prüfen ───────────────────────────────────────────────────────────────
$lock = json_decode((string) file_get_contents($lockFile), true, 512, JSON_THROW_ON_ERROR);
$locked = [];
foreach ($lock['packages'] ?? [] as $p) {
    $locked[$p['name']] = ['version' => ltrim((string) $p['version'], 'v'), 'ref' => (string) ($p['dist']['reference'] ?? '')];
}
$installedJson = json_decode((string) file_get_contents($vendor . '/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
$installed = [];
foreach ($installedJson['packages'] ?? $installedJson as $p) {
    $installed[$p['name']] = ['version' => ltrim((string) $p['version'], 'v'), 'ref' => (string) ($p['dist']['reference'] ?? '')];
}
$meta = [];
foreach (array_keys(PACKAGES) as $name) {
    $l = $locked[$name] ?? fail("{$name} fehlt in der Lockdatei");
    $i = $installed[$name] ?? fail("{$name} fehlt im Vendor-Verzeichnis");
    if ($l['version'] !== $i['version'] || $l['ref'] !== $i['ref']) {
        fail("{$name}: Vendor {$i['version']}@{$i['ref']} ≠ Lock {$l['version']}@{$l['ref']}");
    }
    $meta[$name] = $l;
}

// ── 2. Dateien einlesen und zerlegen ──────────────────────────────────────────────────────────────────────
/**
 * @return array{ns: list<array{name: string, uses: array<string,string>, start: int, end: int}>, classes: array<string, array{deps: list<string>}>, refs: list<string>, toplevel: bool}
 */
function analyse(string $src, string $file): array
{
    $tokens = PhpToken::tokenize($src);
    $n = count($tokens);
    $depth = 0;
    $ns = '';
    $uses = [];
    $classes = [];
    $refs = [];
    $toplevel = false;
    $classDepth = null;   // Tiefe, auf der der aktuelle Klassenrumpf beginnt
    $currentClass = null;

    $resolve = static function (string $name, string $ns, array $uses): string {
        if ($name === '' ) {
            return '';
        }
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }
        if (str_starts_with(strtolower($name), 'namespace\\')) {
            return ltrim($ns . '\\' . substr($name, 10), '\\');
        }
        $first = explode('\\', $name, 2);
        $alias = strtolower($first[0]);
        if (isset($uses[$alias])) {
            return $uses[$alias] . (isset($first[1]) ? '\\' . $first[1] : '');
        }
        return ltrim($ns . '\\' . $name, '\\');
    };
    $next = static function (int $i) use ($tokens, $n): int {
        for ($j = $i + 1; $j < $n; $j++) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }
        return $n;
    };
    $prev = static function (int $i) use ($tokens): int {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (!$tokens[$j]->isIgnorable()) {
                return $j;
            }
        }
        return -1;
    };
    $readNames = static function (int $i, array $stop) use ($tokens, $n, $next): array {
        $names = [];
        for (; $i < $n; $i = $next($i)) {
            $t = $tokens[$i];
            if (in_array($t->text, $stop, true)) {
                break;
            }
            if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
                $names[] = $t->text;
            }
        }
        return [$names, $i];
    };

    $nsList = [];
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            $depth++;
            continue;
        }
        if ($t->text === '}') {
            $depth--;
            if ($classDepth !== null && $depth === $classDepth) {
                $classDepth = null;
                $currentClass = null;
            }
            continue;
        }
        if ($depth === 0 && $t->is(T_NAMESPACE) && !$tokens[$next($i)]->is(T_NS_SEPARATOR)) {
            [$names, $end] = $readNames($next($i), [';', '{']);
            if ($tokens[$end]->text === '{') {
                fail("{$file}: geklammerte namespace-Blöcke werden nicht unterstützt");
            }
            $ns = $names[0] ?? '';
            $uses = [];
            $nsList[] = ['name' => $ns, 'start' => $i, 'end' => $end];
            $i = $end;
            continue;
        }
        if ($t->is(T_USE) && $depth === 0) {
            // Importe: use A\B [as C], use function …, use A\{B, C as D}
            $j = $next($i);
            $kind = 'class';
            if ($tokens[$j]->is([T_FUNCTION, T_CONST])) {
                $kind = strtolower($tokens[$j]->text);
                $j = $next($j);
            }
            $prefix = '';
            $current = '';
            $alias = null;
            $flush = static function () use (&$uses, &$current, &$alias, &$prefix, $kind): void {
                if ($current !== '' && $kind === 'class') {
                    $full = ltrim($prefix . $current, '\\');
                    $short = $alias ?? substr($full, (int) strrpos('\\' . $full, '\\'));
                    $uses[strtolower($short)] = $full;
                }
                $current = '';
                $alias = null;
            };
            for (; $j < $n && $tokens[$j]->text !== ';'; $j = $next($j)) {
                $tk = $tokens[$j];
                if ($tk->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    if ($tokens[$prev($j)]->is(T_AS)) {
                        $alias = $tk->text;
                    } else {
                        $current = $tk->text;
                    }
                } elseif ($tk->text === '{') {
                    $prefix = rtrim($current, '\\') . '\\';
                    $current = '';
                } elseif ($tk->is(T_NS_SEPARATOR)) {
                    // "A\{" wird als T_NAME_QUALIFIED + T_NS_SEPARATOR geliefert
                    continue;
                } elseif ($tk->text === ',' || $tk->text === '}') {
                    $flush();
                }
            }
            $flush();
            $nsList[count($nsList) - 1]['uses'] = $uses;
            $i = $j;
            continue;
        }
        if ($t->is(T_USE) && $classDepth !== null && $depth === $classDepth + 1 && $currentClass !== null) {
            [$names, $end] = $readNames($next($i), [';', '{']);
            foreach ($names as $name) {
                $classes[$currentClass]['deps'][] = $resolve($name, $ns, $uses);
            }
            $i = $end - 1; // '{' der Konfliktauflösung muss gezählt werden
            continue;
        }
        if ($t->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
            $p = $prev($i);
            if ($p >= 0 && ($tokens[$p]->is([T_DOUBLE_COLON, T_NEW]))) {
                continue; // ::class bzw. anonyme Klasse
            }
            $nameIdx = $next($i);
            if (!$tokens[$nameIdx]->is(T_STRING)) {
                continue;
            }
            if ($depth !== 0) {
                // bedingte Deklaration (nette/utils compatibility.php: `if (false) { interface … }`): nur Hinweis
                $toplevel = true;
                continue;
            }
            $fqcn = ltrim($ns . '\\' . $tokens[$nameIdx]->text, '\\');
            $classes[$fqcn] = ['deps' => []];
            [$names, $end] = $readNames($next($nameIdx), ['{']);
            // Basistyp eines Enums (": string") überspringen
            foreach ($names as $name) {
                if (in_array(strtolower($name), ['extends', 'implements', 'string', 'int'], true)) {
                    continue;
                }
                $classes[$fqcn]['deps'][] = $resolve($name, $ns, $uses);
            }
            $currentClass = $fqcn;
            $classDepth = $depth;
            $i = $end - 1;
            continue;
        }
        if ($depth === 0 && !$t->isIgnorable() && !$t->is([T_OPEN_TAG, T_DECLARE]) && $classDepth === null) {
            // Anweisungen außerhalb von Deklarationen (declare-Inhalt, abstract/final/readonly, Attribute ausgenommen)
            if (!in_array($t->text, ['(', ')', ';', '=', 'strict_types', '1'], true)
                && !$t->is([T_ABSTRACT, T_FINAL, T_READONLY, T_ATTRIBUTE, T_LNUMBER, T_STRING])) {
                $toplevel = true;
            }
            if ($t->is(T_STRING) && !in_array($t->text, ['strict_types'], true)) {
                $toplevel = true;
            }
        }
        if ($t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            $refs[] = $resolve($t->text, $ns, $uses);
        } elseif ($t->is(T_CONSTANT_ENCAPSED_STRING) && str_contains($t->text, '\\')) {
            $refs[] = ltrim(stripcslashes(trim($t->text, '\'"')), '\\');
        }
    }
    // Importe zählen als Referenzen (Überschätzung, harmlos)
    foreach ($nsList as $entry) {
        foreach ($entry['uses'] ?? [] as $full) {
            $refs[] = $full;
        }
    }

    return ['ns' => $nsList, 'classes' => $classes, 'refs' => array_values(array_unique($refs)), 'toplevel' => $toplevel, 'tokens' => $tokens];
}

$files = [];       // Pfad => Analyse
$declaredIn = [];  // Klasse (klein) => Pfad
foreach (PACKAGES as $name => [$srcDir]) {
    $root = $vendor . '/' . $name . '/' . $srcDir;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $list = [];
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $list[] = $f->getPathname();
        }
    }
    sort($list, SORT_STRING);
    foreach ($list as $path) {
        $src = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
        if (!str_contains($src, 'declare(strict_types=1)')) {
            fail("{$path}: ohne strict_types (würde im Bündel die Semantik ändern)");
        }
        $a = analyse($src, $path);
        $a['package'] = $name;
        $a['rel'] = $name . '/' . $srcDir . substr($path, strlen($root));
        $files[$path] = $a;
        foreach (array_keys($a['classes']) as $c) {
            $declaredIn[strtolower($c)] = $path;
        }
    }
}
// Klassen-Aliase aus compatibility.php (class_alias) gelten als in der Datei deklariert
foreach ($files as $path => $a) {
    if (str_ends_with($path, 'nette/utils/src/compatibility.php')) {
        $declaredIn[strtolower('Nette\\Utils\\IHtmlString')] = $path;
        $declaredIn[strtolower('Nette\\Localization\\ITranslator')] = $path;
    }
}

// ── 3. Transitive Hülle ab den Wurzeln ────────────────────────────────────────────────────────────────────
$selected = [];
$queue = [];
$roots = ROOTS;
// Zusätzliche Wurzeln: jede Einfuhr `use <Präfix>\…;` der genannten Dateien (z. B. ContentRenderer.php) muss im Bündel liegen.
foreach ((array) ($opts['roots-from'] ?? []) as $rootFile) {
    $src = (string) file_get_contents($rootFile);
    if ($prefix !== '' && preg_match_all('/^use\s+' . preg_quote($prefix . '\\', '/') . '([A-Za-z0-9_\\\\]+)\s*;/m', $src, $mm) > 0) {
        foreach ($mm[1] as $imported) {
            $roots[] = $imported;
        }
    }
}
foreach (array_unique($roots) as $root) {
    $queue[] = $declaredIn[strtolower($root)] ?? fail("Wurzel {$root} nicht gefunden");
}
while ($queue) {
    $path = array_shift($queue);
    if (isset($selected[$path])) {
        continue;
    }
    $selected[$path] = true;
    foreach ($files[$path]['refs'] as $ref) {
        $target = $declaredIn[strtolower($ref)] ?? null;
        if ($target !== null && !isset($selected[$target])) {
            $queue[] = $target;
        }
    }
    foreach ($files[$path]['classes'] as $c) {
        foreach ($c['deps'] as $dep) {
            $target = $declaredIn[strtolower($dep)] ?? null;
            if ($target !== null && !isset($selected[$target])) {
                $queue[] = $target;
            }
        }
    }
}

// ── 4. Topologische Reihenfolge (extends/implements/use-Traits), stabil nach Pfad ──────────────────────────
$paths = array_keys($selected);
sort($paths, SORT_STRING);
$deps = [];
foreach ($paths as $path) {
    $own = array_map('strtolower', array_keys($files[$path]['classes']));
    $deps[$path] = [];
    foreach (EXTRA_DEPS as $suffix => $types) {
        if (str_ends_with($path, $suffix)) {
            foreach ($types as $type) {
                $deps[$path][$declaredIn[strtolower($type)] ?? fail("EXTRA_DEPS: {$type} unbekannt")] = true;
            }
        }
    }
    foreach ($files[$path]['classes'] as $c) {
        foreach ($c['deps'] as $dep) {
            $l = strtolower($dep);
            if (in_array($l, $own, true)) {
                continue;
            }
            $target = $declaredIn[$l] ?? null;
            if ($target === null) {
                foreach (EXTERNAL_PREFIXES as $px) {
                    if (str_starts_with($dep, $px)) {
                        continue 2;
                    }
                }
                if (!class_exists($dep, false) && !interface_exists($dep, false) && !trait_exists($dep, false)) {
                    fwrite(STDERR, "Hinweis: {$files[$path]['rel']} hängt von unbekanntem Typ {$dep} ab\n");
                }
                continue;
            }
            if (!isset($selected[$target])) {
                fail("{$path}: Elterntyp {$dep} liegt nicht im Bündel");
            }
            $deps[$path][$target] = true;
        }
    }
}
$order = [];
$done = [];
$visiting = [];
$visit = function (string $path) use (&$visit, &$order, &$done, &$visiting, $deps, $files): void {
    if (isset($done[$path])) {
        return;
    }
    if (isset($visiting[$path])) {
        fail('Zyklus bei ' . $files[$path]['rel']);
    }
    $visiting[$path] = true;
    $targets = array_keys($deps[$path]);
    sort($targets, SORT_STRING);
    foreach ($targets as $t) {
        $visit($t);
    }
    unset($visiting[$path]);
    $done[$path] = true;
    $order[] = $path;
};
foreach ($paths as $path) {
    $visit($path);
}

// ── 5. Ausgabe ────────────────────────────────────────────────────────────────────────────────────────────
/** Namensräume, die das Bündel unter PREFIX verschiebt (Scoping gegen doppelte Klassen fremder Kopien). */
const SCOPED_ROOTS = ['League\\CommonMark', 'League\\Config', 'Dflydev\\DotAccessData', 'Nette'];

function scopedName(string $name, string $prefix): string
{
    if ($prefix === '') {
        return $name;
    }
    $lead = str_starts_with($name, '\\') ? '\\' : '';
    $bare = ltrim($name, '\\');
    foreach (SCOPED_ROOTS as $root) {
        if ($bare === $root || str_starts_with($bare, $root . '\\')) {
            return $lead . $prefix . '\\' . $bare;
        }
    }
    return $name;
}

function scopedLiteral(string $literal, string $prefix, string $rel): string
{
    if ($prefix === '') {
        return $literal;
    }
    // Klassennamen als Text (z. B. 'Nette\\Utils\\X') würden am Präfix vorbeilaufen. 2.10.7/1.2.0/3.0.3/1.4.0/4.1.6
    // enthalten keine; taucht eine auf, bricht der Bau ab (bewusst keine Textersetzung im Blindflug).
    $text = stripcslashes(substr($literal, 1, -1));
    foreach (SCOPED_ROOTS as $root) {
        if (str_starts_with(ltrim($text, '\\'), $root . '\\')) {
            fail("{$rel}: Klassenname als Zeichenkette {$literal}: Präfix-Umschreibung nicht unterstützt");
        }
    }
    return $literal;
}

function transform(array $a, bool $keepComments, string $prefix): string
{
    /** @var list<PhpToken> $tokens */
    $tokens = $a['tokens'];
    $out = '';
    $nsStarts = [];
    foreach ($a['ns'] as $k => $entry) {
        $nsStarts[$entry['start']] = $k;
    }
    $open = false;
    $skipUntil = -1;
    $n = count($tokens);
    $depth = 0;
    $inUse = false;      // Import-Anweisung auf oberster Ebene
    $inGroup = false;    // innerhalb use A\{ … }
    $clauseHead = true;  // nächster Name ist der Kopf einer Import-Klausel
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if ($i <= $skipUntil) {
            continue;
        }
        if ($t->is(T_OPEN_TAG)) {
            continue;
        }
        if ($t->is(T_CLOSE_TAG)) {
            fail($a['rel'] . ': schließendes ?> wird nicht unterstützt');
        }
        if ($t->is(T_DECLARE) && $depth === 0) {
            // declare(strict_types=1); entfernen (steht einmal am Anfang des Bündels)
            for ($j = $i; $j < $n && $tokens[$j]->text !== ';'; $j++);
            $skipUntil = $j;
            continue;
        }
        if (isset($nsStarts[$i])) {
            $entry = $a['ns'][$nsStarts[$i]];
            if ($open) {
                $out = rtrim($out) . "\n}\n";
            }
            $out .= 'namespace ' . scopedName($entry['name'], $prefix) . " {\n";
            $open = true;
            $skipUntil = $entry['end'];
            continue;
        }
        if (!$keepComments && $t->is([T_COMMENT, T_DOC_COMMENT])) {
            // Kommentar entfernen; einen Zeilenumbruch behalten, wenn der Kommentar ihn trug
            if (str_ends_with($t->text, "\n")) {
                $out .= "\n";
            }
            continue;
        }
        if ($t->text === '{' || $t->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
            if ($inUse) {
                $inGroup = true;
            } else {
                $depth++;
            }
        } elseif ($t->text === '}') {
            if ($inUse && $inGroup) {
                $inGroup = false;
            } else {
                $depth--;
            }
        }
        if ($depth === 0 && $t->is(T_USE)) {
            $inUse = true;
            $inGroup = false;
            $clauseHead = true;
            $out .= $t->text;
            continue;
        }
        if ($inUse) {
            if ($t->text === ';') {
                $inUse = false;
            } elseif ($t->text === ',') {
                $clauseHead = !$inGroup;
            } elseif ($t->is([T_FUNCTION, T_CONST])) {
                // use function/const: Funktionen/Konstanten der Pakete gibt es nicht; Kopf bleibt Kopf
            } elseif ($clauseHead && $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $out .= scopedName($t->text, $prefix);
                $clauseHead = false;
                continue;
            }
            $out .= $t->text;
            continue;
        }
        if ($t->is(T_NAME_FULLY_QUALIFIED)) {
            $out .= scopedName($t->text, $prefix);
            continue;
        }
        if ($t->is(T_CONSTANT_ENCAPSED_STRING)) {
            $out .= scopedLiteral($t->text, $prefix, $a['rel']);
            continue;
        }
        $out .= $t->text;
    }
    if (!$open) {
        $out = "namespace {\n" . $out;
    }
    $out = rtrim($out) . "\n}\n";
    // Leerzeilenfolgen kürzen (rein kosmetisch, deterministisch)
    $out = (string) preg_replace("/\n[ \t]*(\n[ \t]*){2,}/", "\n\n", $out);
    $out = (string) preg_replace("/[ \t]+\n/", "\n", $out);

    return $out;
}

$versions = [];
foreach ($meta as $name => $m) {
    $versions[] = sprintf(' *   %-24s %-8s %s', $name, $m['version'], $m['ref']);
}
$classCount = 0;
$classIndex = [];
foreach ($order as $path) {
    foreach (array_keys($files[$path]['classes']) as $c) {
        $classIndex[] = $c;
        $classCount++;
    }
}
$header = "<?php\n/**\n * 365CMS-Bündel: league/commonmark mit Abhängigkeiten (Teilmenge), eine Datei mit geklammerten namespace-Blöcken.\n"
    . " * GENERIERT, nicht von Hand ändern. Erzeugt aus .github/deps/composer.lock mit .github/deps/build-commonmark-bundle.php.\n"
    . " * Lizenzen: siehe LICENSE neben dieser Datei (BSD-3-Clause bzw. MIT; nette/* unter BSD-3-Clause gewählt).\n"
    . " * Copyright (c) Colin O'Dell und Mitwirkende (league/commonmark, league/config); Dragonfly Development Inc.,\n"
    . " *   Beau Simensen (dflydev/dot-access-data); David Grudl (nette/schema, nette/utils).\n"
    . " * Pakete:\n" . implode("\n", $versions) . "\n"
    . ($prefix !== '' ? ' * Namensräume verschoben unter ' . $prefix . '\\ (z. B. ' . $prefix . '\\League\\CommonMark\\MarkdownConverter).' . "\n" : '')
    . ' * Dateien: ' . count($order) . ', Typen: ' . $classCount . "\n */\n\ndeclare(strict_types=1);\n\n";
$body = '';
if ($guard) {
    // Direktaufruf ohne CMS-Kontext: nichts ausführen (Deklarationen sind ohnehin folgenlos)
    $body .= "namespace {\n    if (!\\defined('ABSPATH')) {\n        return;\n    }\n}\n\n";
}
foreach ($order as $path) {
    $body .= "// " . $files[$path]['rel'] . "\n" . transform($files[$path], $keepComments, $prefix) . "\n";
}
$bundleBytes = $header . rtrim($body) . "\n";

$license = "Lizenzen der in commonmark.php enthaltenen Pakete\n=================================================\n\n";
foreach (PACKAGES as $name => [, $lic]) {
    $license .= str_repeat('-', 72) . "\n{$name} {$meta[$name]['version']} ({$meta[$name]['ref']})\n" . str_repeat('-', 72) . "\n\n"
        . rtrim(str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($vendor . '/' . $name . '/' . $lic))) . "\n\n";
}
$licenseBytes = rtrim($license) . "\n";

if ($verify) {
    $same = true;
    foreach ([$out => $bundleBytes, $licenseOut => $licenseBytes] as $path => $bytes) {
        $current = is_file($path) ? str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path)) : null;
        if ($current !== $bytes) {
            fwrite(STDERR, "ABWEICHUNG: {$path} entspricht nicht dem Bau aus der Lockdatei (SHA-256 neu " . hash('sha256', $bytes) . ")\n");
            $same = false;
        } else {
            printf("gleich: %s (%d Bytes, SHA-256 %s)\n", $path, strlen($bytes), hash('sha256', $bytes));
        }
    }
    exit($same ? 0 : 1);
}
file_put_contents($out, $bundleBytes);
file_put_contents($licenseOut, $licenseBytes);

if (isset($opts['list'])) {
    file_put_contents((string) $opts['list'], implode("\n", array_map(fn ($p) => $files[$p]['rel'], $order)) . "\n");
}
$perPkg = [];
foreach ($order as $p) {
    $perPkg[$files[$p]['package']] = ($perPkg[$files[$p]['package']] ?? 0) + 1;
    if ($files[$p]['toplevel']) {
        fwrite(STDERR, "Hinweis: Anweisungen außerhalb von Deklarationen in {$files[$p]['rel']}\n");
    }
}
printf("Bündel: %s (%d Bytes, SHA-256 %s)\nLizenz: %s (SHA-256 %s)\nDateien: %d von %d, Typen: %d\n", $out, filesize($out), hash_file('sha256', $out), $licenseOut, hash_file('sha256', $licenseOut), count($order), count($files), $classCount);
foreach ($perPkg as $p => $c) {
    printf("  %-24s %d Dateien\n", $p, $c);
}
