<?php
/**
 * Gegenprobe der Community-Suiten (PR #2a, Entwurfsvertrag §6): „Jeder Test läuft einmal gegen eine absichtlich falsche
 * Umsetzung und muss dort fehlschlagen.“
 *
 * Der Treiber liest den Katalog `TESTS/community-mutants/catalog.php` (Mutante => Datei, Suchtext, Ersatz, Suite, …) und startet
 * je Mutante die zuständige Suite in einem eigenen PHP-Prozess mit `CMS_TEST_MUTANT=<name>`; der Autoloader-Haken
 * `TESTS/lib/Mutant.php` ersetzt dort genau einen Text der Quelldatei. Erwartet wird Exit 1 (Suite scheitert = Mutante erkannt):
 *
 *   Exit 1   erkannt
 *   Exit 0   überlebt: die Suite prüft die Regel nicht (Lücke der Suite = Fehler dieses Laufs)
 *   Exit 99  Katalog veraltet (Suchtext nicht genau einmal, unbekannter Name, Mutante nie angewendet) = Fehler
 *   sonst    Absturz oder Zeitüberschreitung = Fehler
 *
 * Vorlauf ohne Suiten: Namen eindeutig, jede Datei vorhanden, jeder Suchtext genau einmal, der Ersatz ändert den Text, die Mutante
 * ist gültiges PHP, jede Suite bekannt, jede Regel benannt. Gegenprobe der Gegenprobe: ein harmloser, ein veralteter und ein
 * nie angewendeter Mutant (eigener Katalog über `CMS_TEST_MUTANT_CATALOG`) müssen als überlebt/Katalogfehler erkannt werden.
 * Die Suite `user-role-hook` hat ihren eigenen Mechanismus (14 Mutanten in der Suite); der Treiber ruft sie einmal auf.
 * `community-settings` prüft ihre Regeln mit eigenen Handmutanten und bleibt unberührt.
 *
 *   php TESTS/community-mutants/run.php [-v]
 *   CMS_TEST_MUTANTS=all php TESTS/community-mutants/run.php            # ganzer Katalog (Standard: quick, eine Teilmenge)
 *   CMS_TEST_MUTANTS=all CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/community-mutants/run.php   # mit MariaDB-Mutanten
 *
 * Umgebung: `CMS_TEST_MUTANTS` (quick|all), `CMS_TEST_MUTANT_JOBS` (parallele Prozesse, Standard Zahl der Kerne, 2 bis 6),
 * `CMS_TEST_MUTANT_TIMEOUT` (Sekunden je Mutante, Standard 180), `CMS_TEST_MUTANT_CATALOG` (nur diese Katalogdatei: Abnahme eines
 * Umsetzers; die Zählregeln des ganzen Katalogs entfallen dann). Mutanten mit needsDb laufen nur mit CMS_TEST_DB_HOST und
 * CMS_TEST_DB_USER, Mutanten mit `requires` nur mit den genannten PHP-Erweiterungen; alle anderen laufen mit leerer
 * Datenbank-Umgebung (wie in der CI) und lassen die MariaDB-Fälle der Suiten aus.
 *
 * Suiten (PR #2b): jede `TESTS/community-<suite>/run.php` außer `community-mutants` und `community-settings` (`CM_SUITES` per glob).
 * Die vier Suiten aus #2a brauchen je mindestens zehn Mutanten, jede weitere mindestens eine und davon eine in `quick`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

require_once dirname(__DIR__) . '/lib/TestRunner.php';
require_once dirname(__DIR__) . '/lib/Mutant.php';

T::$verbose = in_array('-v', $argv ?? [], true);

const CM_ROOT = __DIR__ . '/../..';

/** Suiten aus #2a: je mindestens zehn Mutanten. */
const CM_LEGACY_SUITES = ['community-repositories', 'community-access', 'community-content', 'community-db'];

/** Suiten ohne Mutanten-Haken (`Mutant::load`) bzw. der Treiber selbst; sie stehen nie in CM_SUITES. */
const CM_EXCLUDED_SUITES = ['community-mutants', 'community-settings'];

/**
 * Zieldateien außerhalb von `core/Services/Community/` (Vertrag DESIGN-2b §6 W0, GK2): die Core-Dateien, auf denen das Modul aufbaut
 * und die PR #2b ändert. Jede andere Datei (z. B. `core/Services/SettingsService.php`) lehnt der Katalogtest ab.
 */
const CM_ALLOWED_CORE_FILES = [
    'core/Hooks.php', 'core/Slug.php',
    'core/Services/PurifierService.php', 'core/Services/NotificationService.php', 'core/Services/MailQueueService.php',
    'core/Services/FeatureHooks/CommunityHooks.php',
];

/**
 * Suiten, gegen die Mutanten laufen (Suite => Skript, relativ zum Repository): `TESTS/community-<suite>/run.php` ohne CM_EXCLUDED_SUITES.
 *
 * @return array<string, string>
 */
function cmSuites(): array
{
    $suites = [];
    foreach (glob(CM_ROOT . '/TESTS/community-*/run.php') ?: [] as $script) {
        $name = basename(dirname($script));
        if (!in_array($name, CM_EXCLUDED_SUITES, true)) {
            $suites[$name] = 'TESTS/' . $name . '/run.php';
        }
    }
    ksort($suites);

    return $suites;
}

define('CM_SUITES', cmSuites());

/** Darf eine Mutante diese Datei (relativ zu CMS/) ändern? Community-Code und CM_ALLOWED_CORE_FILES, nie mit `..`. */
function cmFileAllowed(string $file): bool
{
    return !str_contains($file, '..') && (str_starts_with($file, 'core/Services/Community/') || in_array($file, CM_ALLOWED_CORE_FILES, true));
}

/**
 * Probleme eines Katalogeintrags (leer = gültig): Name, Suite, Regel, needsDb, Zieldatei, Suchtext genau einmal, Ersatz, PHP-Syntax,
 * env und requires.
 *
 * @param array{file: string, search: string, replace: string, suite: string, needsDb: bool, rule: string, quick: bool, env: array<string, string>, requires: list<string>} $entry
 * @return list<string>
 */
function cmEntryProblems(string $name, array $entry): array
{
    $problems = [];
    if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $name) !== 1) {
        $problems[] = $name . ': Name nur aus Kleinbuchstaben, Ziffern und Bindestrichen';
    }
    if (!isset(CM_SUITES[$entry['suite']])) {
        return [...$problems, $name . ': unbekannte Suite ' . $entry['suite']];
    }
    if ($entry['rule'] === '') {
        $problems[] = $name . ': Regel fehlt';
    }
    if (($entry['suite'] === 'community-db') !== $entry['needsDb']) {
        $problems[] = $name . ': needsDb muss genau für die Suite community-db gelten';
    }
    foreach (array_keys($entry['env']) as $variable) {
        if (preg_match('/^CMS_TEST_[A-Z_]+$/', (string) $variable) !== 1) {
            $problems[] = $name . ': env nur CMS_TEST_*-Variablen: ' . $variable;
        }
    }
    foreach ($entry['requires'] as $extension) {
        if (preg_match('/^[a-z0-9_]+$/', $extension) !== 1) {
            $problems[] = $name . ': requires nennt nur Namen von PHP-Erweiterungen: ' . $extension;
        }
    }
    if (!cmFileAllowed($entry['file'])) {
        return [...$problems, $name . ': Datei liegt nicht unter core/Services/Community/ und nicht in der Allowlist (' . implode(', ', CM_ALLOWED_CORE_FILES) . '): ' . $entry['file']];
    }
    $path = Mutant::root() . $entry['file'];
    if (!is_file($path)) {
        return [...$problems, $name . ': Datei fehlt: ' . $entry['file']];
    }
    $text = (string) file_get_contents($path);
    $hits = Mutant::occurrences($text, $entry['search']);
    if ($hits !== 1) {
        return [...$problems, $name . ': Suchtext kommt ' . $hits . 'x vor statt genau einmal in ' . $entry['file'] . ': ' . Mutant::excerpt($entry['search'])];
    }
    if ($entry['replace'] === $entry['search']) {
        return [...$problems, $name . ': Ersatz ist gleich dem Suchtext (die Mutante ändert nichts)'];
    }
    try {
        token_get_all((string) Mutant::mutate($text, $entry['search'], $entry['replace']), TOKEN_PARSE);
    } catch (\ParseError $e) {
        $problems[] = $name . ': die Mutante ist kein gültiges PHP: ' . $e->getMessage();
    }

    return $problems;
}

/** Ist eine echte Datenbank für die Mutanten mit needsDb konfiguriert? */
function cmDbConfigured(): bool
{
    return (string) getenv('CMS_TEST_DB_HOST') !== '' && (string) getenv('CMS_TEST_DB_USER') !== '' && extension_loaded('pdo_mysql');
}

/** Bewertung eines Exit-Codes: `detected`, `survived`, `broken` oder `crashed`. */
function cmVerdict(int $exit): string
{
    return match ($exit) {
        1 => 'detected',
        0 => 'survived',
        Mutant::EXIT_BROKEN => 'broken',
        default => 'crashed',
    };
}

/** Anzahl paralleler Prozesse. */
function cmParallel(): int
{
    $jobs = (int) getenv('CMS_TEST_MUTANT_JOBS');
    if ($jobs > 0) {
        return min(16, $jobs);
    }
    $cores = PHP_OS_FAMILY === 'Windows' ? 2 : (int) trim((string) @shell_exec('nproc 2>/dev/null'));

    return max(2, min(6, $cores));
}

/**
 * Startet je Auftrag einen Suite-Prozess und wartet auf alle (höchstens `$parallel` gleichzeitig).
 *
 * @param array<string, array{suite: string, env: array<string, string>, script?: string, verbose?: bool}> $jobs Name => Suite, zusätzliche Umgebung, optional anderes Skript und `-v`
 * @return array<string, array{exit: int, output: string, seconds: float, timeout: bool}>
 */
function cmRunAll(array $jobs, int $parallel, int $timeout): array
{
    $queue = array_keys($jobs);
    $running = [];
    $results = [];
    while ($queue !== [] || $running !== []) {
        while ($queue !== [] && count($running) < $parallel) {
            $name = array_shift($queue);
            $job = $jobs[$name];
            $output = (string) tempnam(sys_get_temp_dir(), 'cms-mutant-run-');
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(CM_ROOT . '/' . ($job['script'] ?? CM_SUITES[$job['suite']])) . (!empty($job['verbose']) ? ' -v' : '');
            $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']], $pipes, CM_ROOT, array_merge(getenv(), $job['env']));
            $running[$name] = ['process' => $process, 'output' => $output, 'started' => microtime(true)];
        }
        foreach ($running as $name => $state) {
            $status = proc_get_status($state['process']);
            $elapsed = microtime(true) - $state['started'];
            $timedOut = $status['running'] && $elapsed > $timeout;
            if ($status['running'] && !$timedOut) {
                continue;
            }
            if ($timedOut) {
                proc_terminate($state['process']);
                usleep(100000);
            }
            $results[$name] = [
                'exit' => $timedOut ? -1 : (int) $status['exitcode'],
                'output' => (string) file_get_contents($state['output']),
                'seconds' => $elapsed,
                'timeout' => $timedOut,
            ];
            proc_close($state['process']);
            @unlink($state['output']);
            unset($running[$name]);
        }
        usleep(10000);
    }

    return $results;
}

/** Letzte nichtleere Zeilen der Ausgabe (für die Fehlermeldung). */
function cmTail(string $output, int $lines = 4): string
{
    $parts = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $line): bool => $line !== ''));

    return implode(' | ', array_slice($parts, -$lines));
}

/** Umgebung für den Suite-Prozess einer Mutante. */
function cmEnv(string $name, array $entry): array
{
    $env = ['CMS_TEST_MUTANT' => $name] + $entry['env'];
    if (!$entry['needsDb']) {
        // Ohne Datenbank, wie in der CI: die MariaDB-Fälle der Suiten laufen nicht.
        $env += ['CMS_TEST_DB_HOST' => '', 'CMS_TEST_DB_USER' => '', 'CMS_TEST_DB_PASS' => ''];
    }

    return $env;
}

$catalog = Mutant::catalog();
$mode = strtolower((string) getenv('CMS_TEST_MUTANTS'));
$mode = $mode === '' ? 'quick' : $mode;
$started = microtime(true);

// ── Vorlauf: der Katalog selbst (ohne eine Suite zu starten) ──────────────────────────────────────────────────────────────

T::run('Katalog: Namen eindeutig, Dateien und Suchtexte (genau einmal), Ersatz, gültiges PHP, Suite, Regel', static function () use ($catalog): void {
    $custom = (string) getenv('CMS_TEST_MUTANT_CATALOG') !== '';
    $source = '';
    foreach (Mutant::catalogFiles() as $catalogFile) {
        $source .= (string) file_get_contents($catalogFile);
    }
    preg_match_all("/^    '([^']+)' => \\[/m", $source, $declared);
    $names = $declared[1];
    $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));
    T::same([], $duplicates, 'keine doppelten Namen (ein doppelter Schlüssel überschreibt im PHP-Array still den ersten)');
    T::same(count($names), count($catalog), 'jede Zeile des Katalogs ist ein Eintrag');

    $problems = [];
    $perSuite = array_fill_keys(array_keys(CM_SUITES), 0);
    $quickPerSuite = array_fill_keys(array_keys(CM_SUITES), 0);
    $quick = 0;
    foreach ($catalog as $name => $entry) {
        array_push($problems, ...cmEntryProblems((string) $name, $entry));
        if (isset($perSuite[$entry['suite']])) {
            $perSuite[$entry['suite']]++;
            $quickPerSuite[$entry['suite']] += $entry['quick'] ? 1 : 0;
        }
        $quick += $entry['quick'] ? 1 : 0;
    }
    T::same([], $problems, 'Katalog gültig (Dateien, Suchtexte genau einmal, Ersatz, PHP-Syntax, Suite, Regel, env, requires)');
    T::same(CM_LEGACY_SUITES, array_values(array_intersect(CM_LEGACY_SUITES, array_keys(CM_SUITES))), 'CM_SUITES enthält die vier Suiten aus #2a');
    T::same([], array_values(array_intersect(CM_EXCLUDED_SUITES, array_keys(CM_SUITES))), 'community-mutants und community-settings stehen nicht in CM_SUITES');
    if ($custom) {
        T::info('eigener Katalog (' . basename((string) getenv('CMS_TEST_MUTANT_CATALOG')) . '): Zählregeln des ganzen Katalogs entfallen; ' . count($catalog) . ' Mutanten, davon quick: ' . $quick);
    } else {
        T::check(count($catalog) >= 60 && count($catalog) <= 600, 'der Katalog hat 60 bis 600 Mutanten', (string) count($catalog));
        $legacy = array_intersect_key($perSuite, array_flip(CM_LEGACY_SUITES));
        T::check(min($legacy) >= 10, 'jede Suite aus #2a hat mindestens zehn Mutanten', json_encode($legacy));
        T::same([], array_keys(array_filter($perSuite, static fn (int $n): bool => $n < 1)), 'jede Suite in CM_SUITES hat mindestens eine Mutante', json_encode($perSuite));
        T::same([], array_keys(array_filter($quickPerSuite, static fn (int $n): bool => $n < 1)), 'jede Suite in CM_SUITES hat mindestens eine Mutante in quick', json_encode($quickPerSuite));
        T::check($quick >= 25 && $quick < count($catalog), 'die Teilmenge quick hat mindestens 25 Mutanten und ist kleiner als der Katalog', (string) $quick);
    }
    T::info(count($catalog) . ' Mutanten: ' . json_encode($perSuite) . ', davon quick: ' . $quick);
});

T::run('Katalog: Allowlist der Zieldateien, requires und Suiten per glob (Gegenproben)', static function (): void {
    $entry = static fn (string $file, string $search, string $suite = 'community-access', array $requires = []): array => [
        'file' => $file, 'search' => $search, 'replace' => $search . ' ', 'suite' => $suite, 'needsDb' => $suite === 'community-db',
        'rule' => 'Gegenprobe', 'quick' => false, 'env' => [], 'requires' => $requires,
    ];
    $namespace = 'namespace CMS\\Services;';
    T::same([1, 1], [Mutant::occurrences((string) file_get_contents(Mutant::root() . 'core/Services/SettingsService.php'), $namespace), Mutant::occurrences((string) file_get_contents(Mutant::root() . 'core/Services/PurifierService.php'), $namespace)], 'Voraussetzung: derselbe Suchtext kommt in SettingsService.php und PurifierService.php genau einmal vor');
    $probe = cmEntryProblems('selftest-settings-service', $entry('core/Services/SettingsService.php', $namespace));
    T::check($probe !== [] && str_contains($probe[0], 'Allowlist'), 'eine Mutante auf core/Services/SettingsService.php bleibt abgelehnt', implode(' | ', $probe));
    T::check(cmEntryProblems('selftest-escape', $entry('core/Services/Community/../SettingsService.php', $namespace)) !== [], 'ein Pfad mit .. bleibt abgelehnt');
    foreach (CM_ALLOWED_CORE_FILES as $file) {
        T::check(cmFileAllowed($file), 'Allowlist: ' . $file);
    }
    foreach (['core/Services/SettingsService.php', 'core/Database.php', 'core/Services/Community', 'core/Services/FeatureHooks/Registry.php', 'core/Services/PurifierService.php.bak'] as $file) {
        T::check(!cmFileAllowed($file), 'nicht erlaubt: ' . $file);
    }
    T::same([], cmEntryProblems('selftest-purifier', $entry('core/Services/PurifierService.php', $namespace)), 'eine gültige Mutante auf PurifierService.php ist erlaubt');
    T::check(cmEntryProblems('selftest-requires', $entry('core/Services/PurifierService.php', $namespace, 'community-access', ['GD Lib'])) !== [], 'requires mit ungültigem Erweiterungsnamen wird abgelehnt');
    T::same([], Mutant::missingExtensions(['requires' => ['json', 'pcre']]), 'requires: vorhandene Erweiterungen fehlen nicht');
    T::same(['gibt_es_nicht'], Mutant::missingExtensions(['requires' => ['json', 'gibt_es_nicht']]), 'requires: fehlende Erweiterung wird genannt (Mutante wird übersprungen)');
    $file = (string) tempnam(sys_get_temp_dir(), 'cms-mutant-catalog-');
    file_put_contents($file, '<?php return ' . var_export(['selftest-requires-field' => ['core/Hooks.php', 'x', 'y', 'community-access', false, 'r', false, [], ['gd']]], true) . ';');
    $code = 'putenv("CMS_TEST_MUTANT_CATALOG=" . ' . var_export($file, true) . '); require ' . var_export(dirname(__DIR__) . '/lib/Mutant.php', true) . '; echo json_encode(Mutant::catalog()["selftest-requires-field"]["requires"]);';
    $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
    unlink($file);
    T::same('["gd"]', trim($out), 'Mutant::catalog() liest Feld 8 (requires)');
    T::same(cmSuites(), CM_SUITES, 'CM_SUITES ist die glob-Liste');
    foreach (CM_SUITES as $suite => $script) {
        T::check(is_file(CM_ROOT . '/' . $script) && str_contains((string) file_get_contents(CM_ROOT . '/' . $script), 'Mutant::load('), $suite . ': Suite lädt ihre Klassen über Mutant::load');
    }
});

T::run('Gegenprobe der Gegenprobe: ein harmloser Mutant überlebt (Exit 0), ein veralteter und ein nie angewendeter ergeben Exit 99', static function (): void {
    $banRepository = 'core/Services/Community/Repository/BanRepository.php';
    $text = (string) file_get_contents(Mutant::root() . $banRepository);
    // Harmlos: ändert den Text, nicht das Verhalten (zusätzliche Klammern um eine Bedingung).
    $harmless = "if (!defined('ABSPATH')) {";
    $probe = [
        'selftest-harmless' => [$banRepository, $harmless, "if ((!defined('ABSPATH'))) {", 'community-repositories'],
        'selftest-obsolete' => [$banRepository, 'dieser Text steht nirgends in der Datei', 'x', 'community-repositories'],
        'selftest-never-applied' => ['core/Services/Community/Service/QuoteService.php', '<?php', "<?php\n", 'community-repositories'],
    ];
    T::same(1, Mutant::occurrences($text, $harmless), 'Voraussetzung: der harmlose Suchtext kommt in BanRepository genau einmal vor');
    $file = (string) tempnam(sys_get_temp_dir(), 'cms-mutant-catalog-');
    file_put_contents($file, '<?php return ' . var_export($probe, true) . ';');
    $jobs = [];
    foreach (array_keys($probe) as $name) {
        $jobs[$name] = ['suite' => 'community-repositories', 'env' => ['CMS_TEST_MUTANT' => $name, 'CMS_TEST_MUTANT_CATALOG' => $file, 'CMS_TEST_DB_HOST' => '', 'CMS_TEST_DB_USER' => '']];
    }
    $jobs['selftest-unknown'] = ['suite' => 'community-repositories', 'env' => ['CMS_TEST_MUTANT' => 'selftest-unknown', 'CMS_TEST_MUTANT_CATALOG' => $file, 'CMS_TEST_DB_HOST' => '', 'CMS_TEST_DB_USER' => '']];
    $results = cmRunAll($jobs, 4, 120);
    unlink($file);
    T::same('survived', cmVerdict($results['selftest-harmless']['exit']), 'ein Mutant ohne Verhaltensänderung überlebt (Exit 0): der Treiber erkennt „Lücke der Suite“');
    T::same('broken', cmVerdict($results['selftest-obsolete']['exit']), 'ein veralteter Suchtext ergibt Exit 99 (Katalog veraltet)');
    T::check(str_contains($results['selftest-obsolete']['output'], 'nicht genau einmal'), 'die Meldung nennt den Grund', cmTail($results['selftest-obsolete']['output'], 2));
    T::same('broken', cmVerdict($results['selftest-never-applied']['exit']), 'eine Mutante in einer Datei, die die Suite nie lädt, ergibt Exit 99');
    T::check(str_contains($results['selftest-never-applied']['output'], 'nie angewendet'), 'die Meldung nennt „nie angewendet“', cmTail($results['selftest-never-applied']['output'], 2));
    T::same('broken', cmVerdict($results['selftest-unknown']['exit']), 'ein unbekannter Mutantenname ergibt Exit 99');
    T::same(['detected', 'survived', 'broken', 'crashed'], [cmVerdict(1), cmVerdict(0), cmVerdict(99), cmVerdict(255)], 'Bewertung der Exit-Codes');
});

// ── Gegenprobe: je Mutante die Suite starten ──────────────────────────────────────────────────────────────────────────────

T::run('Gegenprobe (' . $mode . '): jede Mutante lässt ihre Suite scheitern (Exit 1)', static function () use ($catalog, $mode): void {
    if (!in_array($mode, ['quick', 'all'], true)) {
        T::check(false, 'CMS_TEST_MUTANTS ist quick oder all', $mode);

        return;
    }
    $db = cmDbConfigured();
    $selected = [];
    $skipped = [];
    $withoutExtension = [];
    foreach ($catalog as $name => $entry) {
        if ($mode === 'quick' && !$entry['quick']) {
            continue;
        }
        if ($entry['needsDb'] && !$db) {
            $skipped[] = $name;
            continue;
        }
        $missing = Mutant::missingExtensions($entry);
        if ($missing !== []) {
            $withoutExtension[] = $name . ' (' . implode(', ', $missing) . ')';
            continue;
        }
        $selected[$name] = $entry;
    }
    if ($skipped !== []) {
        T::info('übersprungen (brauchen CMS_TEST_DB_HOST/CMS_TEST_DB_USER und pdo_mysql): ' . implode(', ', $skipped));
        echo '  Hinweis: ' . count($skipped) . ' Mutanten der MariaDB-Suite übersprungen (CMS_TEST_DB_HOST/CMS_TEST_DB_USER nicht gesetzt)' . PHP_EOL;
    }
    if ($withoutExtension !== []) {
        T::info('übersprungen (PHP-Erweiterung fehlt): ' . implode(', ', $withoutExtension));
        echo '  Hinweis: ' . count($withoutExtension) . ' Mutanten übersprungen (PHP-Erweiterung fehlt)' . PHP_EOL;
    }
    $timeout = max(10, (int) (getenv('CMS_TEST_MUTANT_TIMEOUT') ?: 180));
    $parallel = cmParallel();
    $free = array_filter($selected, static fn (array $e): bool => !$e['needsDb']);
    $withDb = array_filter($selected, static fn (array $e): bool => $e['needsDb']);
    $results = [];
    $began = microtime(true);
    foreach ([[$free, $parallel], [$withDb, min(2, $parallel)]] as [$group, $width]) {
        $jobs = [];
        foreach ($group as $name => $entry) {
            $jobs[$name] = ['suite' => $entry['suite'], 'env' => cmEnv($name, $entry)];
        }
        $results += cmRunAll($jobs, $width, $timeout);
    }
    $detected = 0;
    $perSuite = [];
    foreach ($selected as $name => $entry) {
        $r = $results[$name];
        $verdict = $r['timeout'] ? 'timeout' : cmVerdict($r['exit']);
        $perSuite[$entry['suite']] = ($perSuite[$entry['suite']] ?? 0.0) + $r['seconds'];
        $label = 'Mutante „' . $name . '“ (' . $entry['rule'] . ')';
        $detail = match ($verdict) {
            'detected' => '',
            'survived' => 'Mutante überlebt: ' . $entry['suite'] . ' bleibt grün, die Regel ist nicht abgesichert',
            'broken' => 'Katalog veraltet (Exit 99): ' . cmTail($r['output'], 2),
            'timeout' => 'Zeitüberschreitung nach ' . $timeout . ' s',
            default => 'Absturz (Exit ' . $r['exit'] . '): ' . cmTail($r['output'], 3),
        };
        $detected += $verdict === 'detected' ? 1 : 0;
        T::check($verdict === 'detected', $label . ' wird erkannt (' . round($r['seconds'], 1) . ' s)', $detail);
    }
    $slowest = $results;
    uasort($slowest, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
    T::info('Laufzeit je Suite (Summe der Prozesse): ' . json_encode(array_map(static fn (float $s): float => round($s, 1), $perSuite)) . '; langsamste: ' . implode(', ', array_map(static fn (string $n): string => $n . ' ' . round($slowest[$n]['seconds'], 1) . ' s', array_slice(array_keys($slowest), 0, 3))));
    echo '  ' . $detected . ' von ' . count($selected) . ' Mutanten erkannt (' . $mode . ', ' . $parallel . ' Prozesse parallel, ' . round(microtime(true) - $began, 1) . ' s)' . PHP_EOL;
});

// ── user-role-hook: eigener Mechanismus ───────────────────────────────────────────────────────────────────────────────────

T::run('user-role-hook: die Suite führt ihre eigene Gegenprobe aus (14 Mutanten der beiden Quelldateien)', static function (): void {
    $results = cmRunAll(['user-role-hook' => ['suite' => 'community-db', 'script' => 'TESTS/user-role-hook/run.php', 'verbose' => true, 'env' => ['CMS_TEST_MUTANT' => '']]], 1, 300);
    $r = $results['user-role-hook'];
    T::same(0, $r['exit'], 'Suite user-role-hook ist grün (inklusive ihrer Gegenprobe)', cmTail($r['output'], 5));
    $found = preg_match_all('/Mutante „[^“]+“ \([^)]*\) wird erkannt/u', $r['output']);
    T::check($found >= 10, 'die Suite meldet mindestens zehn erkannte Mutanten', (string) $found);
    T::info('user-role-hook: ' . $found . ' Mutanten in ' . round($r['seconds'], 1) . ' s');
});

echo PHP_EOL . 'Laufzeit des Treibers (' . $mode . '): ' . round(microtime(true) - $started, 1) . ' s' . PHP_EOL;

T::finish();
