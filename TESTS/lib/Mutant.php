<?php
/**
 * Gegenprobe-Haken für die Community-Suiten (PR #2a, Entwurfsvertrag §6): Mutanten statt Mutationsframework.
 *
 * Jede Suite lädt ihre Klassen über einen Autoloader. Statt `require $file` ruft er `Mutant::load($file)`:
 *
 * - Ohne Umgebungsvariable `CMS_TEST_MUTANT` ist das ein gewöhnliches `require` (Original).
 * - Mit `CMS_TEST_MUTANT=<name>` liest der Haken die Originaldatei, ersetzt GENAU EINEN Text (Suchtext → Ersatz laut
 *   `TESTS/community-mutants/catalog.php`) und lädt das Ergebnis aus einer temporären Datei. Die Suite muss dann scheitern
 *   (Exit 1); überlebt die Mutante (Exit 0), ist die Suite an dieser Regel zu schwach.
 * - Exit 99 (Infrastrukturfehler, kein „erkannt“): unbekannter Mutantenname, der Suchtext kommt nicht genau einmal in der
 *   Datei vor (Katalog nach einem Refactoring veraltet) oder die Mutante wurde in diesem Prozess nie angewendet (falsche
 *   Suite: die Datei wird nicht geladen). Unterprozesse einer Suite (Wettlauf-Worker) erben die Variable und setzen
 *   `Mutant::$requireApplied = false`, weil sie nicht jede Datei laden.
 *
 * Katalogeintrag (Liste): `[Datei relativ zu CMS/, Suchtext, Ersatz, Suite, needsDb, Regel, quick, env]`; ab `needsDb` ist alles
 * optional (`false`, leer, `false`, `[]`). `env` sind zusätzliche Umgebungsvariablen für den Suite-Prozess (z. B.
 * `CMS_TEST_DB_SECTIONS` der Suite `community-db`). Der Treiber `TESTS/community-mutants/run.php` startet je Mutante die Suite in
 * einem eigenen Prozess. `CMS_TEST_MUTANT_CATALOG=<Datei>` ersetzt den Katalog (nur für die Selbstprüfung des Treibers).
 *
 * Nutzung im Autoloader einer Suite:
 *
 *   require_once dirname(__DIR__) . '/lib/Mutant.php';
 *   spl_autoload_register(static function (string $class): void {
 *       ...
 *       if (is_file($file)) { Mutant::load($file); }
 *   });
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

final class Mutant
{
    /** Umgebungsvariable mit dem Namen der Mutante. */
    public const ENV = 'CMS_TEST_MUTANT';

    /** Exit-Code für Infrastrukturfehler (Katalog veraltet, unbekannte Mutante, nie angewendet). */
    public const EXIT_BROKEN = 99;

    /** Ob eine in diesem Prozess nie angewendete Mutante am Prozessende Exit 99 auslöst (Worker schalten es ab). */
    public static bool $requireApplied = true;

    private static ?string $name = null;
    private static bool $applied = false;
    private static bool $registered = false;
    private static ?string $directory = null;
    /** @var array<string, array{file: string, search: string, replace: string, suite: string, needsDb: bool, rule: string, quick: bool, env: array<string, string>}>|null */
    private static ?array $catalog = null;

    /** Name der aktiven Mutante, leer ohne Gegenprobe. */
    public static function active(): string
    {
        return self::$name ??= (string) getenv(self::ENV);
    }

    /** Wurzel der Quelldateien (`CMS/`, mit Schrägstrich am Ende), unabhängig von `ABSPATH`. */
    public static function root(): string
    {
        return dirname(__DIR__, 2) . '/CMS/';
    }

    /**
     * Der Katalog mit benannten Feldern.
     *
     * @return array<string, array{file: string, search: string, replace: string, suite: string, needsDb: bool, rule: string, quick: bool, env: array<string, string>}>
     */
    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $path = (string) getenv('CMS_TEST_MUTANT_CATALOG');
            /** @var array<string, array<int, mixed>> $raw */
            $raw = require $path !== '' ? $path : dirname(__DIR__) . '/community-mutants/catalog.php';
            self::$catalog = [];
            foreach ($raw as $name => $row) {
                self::$catalog[(string) $name] = [
                    'file' => (string) $row[0],
                    'search' => (string) $row[1],
                    'replace' => (string) $row[2],
                    'suite' => (string) $row[3],
                    'needsDb' => (bool) ($row[4] ?? false),
                    'rule' => (string) ($row[5] ?? ''),
                    'quick' => (bool) ($row[6] ?? false),
                    'env' => array_map('strval', (array) ($row[7] ?? [])),
                ];
            }
        }

        return self::$catalog;
    }

    /** Wie oft kommt der Suchtext im Quelltext vor? (Der Katalog verlangt genau einmal.) */
    public static function occurrences(string $source, string $search): int
    {
        return $search === '' ? 0 : substr_count($source, $search);
    }

    /** Quelltext mit genau einer Ersetzung; `null`, wenn der Suchtext nicht genau einmal vorkommt. */
    public static function mutate(string $source, string $search, string $replace): ?string
    {
        if (self::occurrences($source, $search) !== 1) {
            return null;
        }

        return str_replace($search, $replace, $source);
    }

    /**
     * Lädt eine Quelldatei: Original oder (bei `CMS_TEST_MUTANT` und passender Datei) die Mutante.
     * Beendet den Prozess mit Exit 99, wenn der Katalog nicht zur Datei passt.
     */
    public static function load(string $file): void
    {
        $name = self::active();
        if ($name === '') {
            require $file;

            return;
        }
        $entry = self::catalog()[$name] ?? null;
        if ($entry === null) {
            self::fail('unbekannte Mutante „' . $name . '“ (nicht in TESTS/community-mutants/catalog.php)');
        }
        self::register();
        $target = realpath(self::root() . $entry['file']);
        if ($target === false) {
            self::fail('Mutante „' . $name . '“: Datei ' . $entry['file'] . ' fehlt');
        }
        if (realpath($file) !== $target) {
            require $file;

            return;
        }
        $source = (string) file_get_contents($file);
        $mutated = self::mutate($source, $entry['search'], $entry['replace']);
        if ($mutated === null) {
            self::fail('Mutante „' . $name . '“: Suchtext kommt in ' . $entry['file'] . ' nicht genau einmal vor (' . self::occurrences($source, $entry['search']) . ' Treffer): ' . self::excerpt($entry['search']));
        }
        $copy = self::directory() . '/' . md5($entry['file']) . '.php';
        file_put_contents($copy, $mutated);
        self::$applied = true;
        require $copy;
    }

    /** Meldung auf STDERR und Exit 99. */
    public static function fail(string $message): never
    {
        fwrite(STDERR, 'MUTANT-FEHLER: ' . $message . PHP_EOL);
        exit(self::EXIT_BROKEN);
    }

    /** Kurzfassung eines Suchtexts für Meldungen (eine Zeile, höchstens 120 Zeichen). */
    public static function excerpt(string $text): string
    {
        $line = (string) preg_replace('/\s+/', ' ', $text);

        return strlen($line) > 120 ? substr($line, 0, 117) . '...' : $line;
    }

    private static function directory(): string
    {
        if (self::$directory === null) {
            self::$directory = sys_get_temp_dir() . '/cms-mutant-' . getmypid();
            if (!is_dir(self::$directory)) {
                mkdir(self::$directory, 0700, true);
            }
        }

        return self::$directory;
    }

    private static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function(static function (): void {
            if (self::$directory !== null) {
                array_map('unlink', glob(self::$directory . '/*') ?: []);
                @rmdir(self::$directory);
            }
            if (self::$requireApplied && !self::$applied) {
                self::fail('Mutante „' . self::active() . '“ wurde in diesem Prozess nie angewendet (die Datei wird von der Suite nicht geladen: falsche Suite im Katalog?)');
            }
        });
    }
}
