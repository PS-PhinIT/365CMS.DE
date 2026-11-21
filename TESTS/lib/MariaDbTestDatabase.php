<?php
/**
 * Echte MariaDB/MySQL als `CMS\Database` für die Community-Suiten (PR #2a), ohne Singleton.
 *
 * Der Konstruktor von `CMS\Database` ist privat und das Feld `$pdo` privat; die Klasse setzt es per Reflection
 * (PHP 8.1 oder neuer, ohne `setAccessible()`). So laufen `execute()`, `insert()`, `update()`, `getPdo()` und
 * `lastInsertId()` des Cores unverändert gegen die Verbindung. Optional werfen `execute()`/`prepare()` bei Treffern
 * in `$failOn` (Fehlerinjektion für Rücknahme-Tests) oder bei der n-ten Anweisung (`$failAtStatement`, Zähler
 * `$statementCount`: die Suite `TESTS/community-db` fällt so nacheinander an jeder Anweisung eines Dienstaufrufs aus).
 * `$captureParams` hält SQL und gebundene Parameter von `execute()` in `$captured` fest (EXPLAIN mit den echten Parametern);
 * `$logParams`/`paramsAt()` ordnen die Parameter jedem Eintrag von `$log` zu (wie beim SQLite-Double).
 *
 * Jeder Aufruf von `create()` legt eine frische Datenbank `<präfix><pid>_<n>` an (Tabellen aus den echten CREATE-Texten wie
 * das SQLite-Double, Tabellenpräfix `cms_`) und stellt die Sitzung auf die Zone `+01:00`, damit `NOW()`, `CURRENT_TIMESTAMP`
 * und Spaltendefaults (Ortszeit) von den UTC-Werten der Dienste abweichen und auffallen. Das Präfix ist `pr2a_`
 * (`DATABASE_PREFIX`) oder der Wert von `CMS_TEST_DB_PREFIX` (`TestDatabasePrefix`, parallele Umsetzer). Alle angelegten
 * Datenbanken werden am Ende des Prozesses gelöscht (auch nach `exit`), und zwar nur die dieses Prozesses.
 *
 * Verbindung über `CMS_TEST_DB_HOST`, `CMS_TEST_DB_USER` und optional `CMS_TEST_DB_PASS` (Recht CREATE/DROP DATABASE).
 * Ohne diese Variablen überspringt sich die Suite: `MariaDbTestDatabase::configured()` und `skipNotice()`.
 *
 *   if (!MariaDbTestDatabase::configured()) { T::info(MariaDbTestDatabase::skipNotice()); } else { $db = MariaDbTestDatabase::create(); }
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

require_once __DIR__ . '/CommunityTestDatabase.php';
require_once __DIR__ . '/TestDatabasePrefix.php';

use CMS\Database;

final class MariaDbTestDatabase extends Database
{
    use CommunityFixtures;

    /** Standard-Präfix der Test-Datenbanken (ohne `CMS_TEST_DB_PREFIX`); aufgeräumt wird nur, was dieser Prozess selbst angelegt hat. */
    public const DATABASE_PREFIX = 'pr2a_';

    /** @var list<string> Reguläre Ausdrücke: passt einer auf das SQL, wirft `prepare()` eine `PDOException`. */
    public array $failOn = [];
    /** @var array<string, \Throwable> Regulärer Ausdruck => Ausnahme: passt der Ausdruck auf das SQL, wirft `prepare()` genau diese. */
    public array $throwOn = [];
    /** @var list<string> Alle vorbereiteten SQL-Texte. */
    public array $log = [];
    /** @var array<int, bool> Zum Eintrag `$log[$i]`: lief die Anweisung innerhalb einer Transaktion (`PDO::inTransaction()`)? Nur über `txAt()` lesen (wie beim SQLite-Double). */
    public array $logTx = [];
    /** Zahl der bisher vorbereiteten Anweisungen (jeder `prepare()`-Aufruf zählt, auch `insert()`); der Test setzt sie bei Bedarf zurück. */
    public int $statementCount = 0;
    /** Nummer (1 = nächste Anweisung nach dem Zurücksetzen von `$statementCount`), bei der `prepare()` eine `PDOException` wirft; `null` = aus. */
    public ?int $failAtStatement = null;
    /** Ob `execute()` SQL und gebundene Parameter in `$captured` festhält (für EXPLAIN mit den echten Parametern). */
    public bool $captureParams = false;
    /** @var list<array{sql: string, params: array<int|string, mixed>}> */
    public array $captured = [];
    /** @var array<int, list<mixed>> Zum Eintrag `$log[$i]`: die gebundenen Parameter von `execute()`; nur über `paramsAt()` lesen. */
    public array $logParams = [];
    public string $databaseName = '';

    /** @var list<mixed>|null Parameter des laufenden `execute()` für `prepare()` */
    private ?array $pendingParams = null;

    /** @var array<string, string> Name der angelegten Datenbank => Präfix, mit dem sie angelegt wurde */
    private static array $created = [];
    private static bool $shutdownRegistered = false;
    private static int $counter = 0;

    /** Verbindung zu einer vorhandenen Datenbank (Aufrufer: `create()`); setzt das private Feld `$pdo` der Elternklasse. */
    public function __construct(\PDO $pdo, string $databaseName = '', string $prefix = 'cms_')
    {
        (new \ReflectionProperty(Database::class, 'pdo'))->setValue($this, $pdo);
        $this->prefix = $prefix;
        $this->databaseName = $databaseName;
    }

    /** Sind Server und Erweiterung für die Suite konfiguriert? */
    public static function configured(): bool
    {
        return (string) getenv('CMS_TEST_DB_HOST') !== '' && (string) getenv('CMS_TEST_DB_USER') !== '' && extension_loaded('pdo_mysql');
    }

    /** Text für `T::info()`, wenn die Suite übersprungen wird. */
    public static function skipNotice(): string
    {
        return 'übersprungen: CMS_TEST_DB_HOST/CMS_TEST_DB_USER nicht gesetzt (oder pdo_mysql fehlt)';
    }

    /** Wirksames Präfix der Test-Datenbanken: `CMS_TEST_DB_PREFIX`, sonst `DATABASE_PREFIX` (ungültiger Wert: Abbruch, Exit 2). */
    public static function databasePrefix(): string
    {
        return TestDatabasePrefix::resolve(self::DATABASE_PREFIX);
    }

    /**
     * Legt eine frische Datenbank `<präfix><pid>_<n>` mit allen Testtabellen an.
     *
     * @param string $sessionTimeZone Offset der Sitzung (wie `Database::connect()` setzt, Standard `+01:00`)
     * @param string|null $databasePrefix anderes Präfix als das wirksame (nur für die Prüfung des Aufräumens; Regel wie `TestDatabasePrefix`)
     */
    public static function create(string $sessionTimeZone = '+01:00', ?string $databasePrefix = null): self
    {
        if (!self::configured()) {
            throw new \RuntimeException(self::skipNotice());
        }
        if ($databasePrefix !== null && !TestDatabasePrefix::valid($databasePrefix)) {
            throw new \InvalidArgumentException('Ungültiges Präfix der Test-Datenbank: ' . $databasePrefix);
        }
        $prefix = $databasePrefix ?? self::databasePrefix();
        $name = $prefix . getmypid() . '_' . (++self::$counter);
        $admin = self::adminConnection();
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
        $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$created[$name] = $prefix;
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function([self::class, 'dropAll']);
        }

        $pdo = self::connect($name, $sessionTimeZone);
        foreach (CommunityTestSchema::mysqlStatements('cms_') as $sql) {
            $pdo->exec($sql);
        }

        return new self($pdo, $name);
    }

    /** Neue Verbindung auf dieselbe Datenbank (parallele Sitzungen im Test), eigene Zone. */
    public function connection(string $sessionTimeZone = '+01:00'): \PDO
    {
        return self::connect($this->databaseName, $sessionTimeZone);
    }

    /** Verbindung wie `Database::connect()`: Ausnahmen, Objekte als Standard, native Prepared Statements, Sitzungszone. */
    public static function connect(string $databaseName, string $sessionTimeZone = '+01:00'): \PDO
    {
        $pdo = new \PDO(
            'mysql:host=' . getenv('CMS_TEST_DB_HOST') . ';dbname=' . $databaseName . ';charset=utf8mb4',
            (string) getenv('CMS_TEST_DB_USER'),
            (string) getenv('CMS_TEST_DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ, \PDO::ATTR_EMULATE_PREPARES => false]
        );
        $pdo->exec("SET time_zone = '{$sessionTimeZone}'");

        return $pdo;
    }

    /** Löscht diese Datenbank sofort (sonst am Prozessende). */
    public function dispose(): void
    {
        if ($this->databaseName !== '' && isset(self::$created[$this->databaseName])) {
            self::adminConnection()->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
            unset(self::$created[$this->databaseName]);
        }
    }

    /**
     * Löscht die von diesem Prozess angelegten Test-Datenbanken (Shutdown-Funktion): ohne Angabe alle, mit `$prefix` nur die mit
     * diesem Präfix angelegten. Eine Datenbank, die dieser Prozess nicht angelegt hat, bleibt immer stehen, auch bei passendem
     * Präfix (parallele Läufe mit eigenem `CMS_TEST_DB_PREFIX` stören sich nicht).
     */
    public static function dropAll(?string $prefix = null): void
    {
        $names = array_keys(array_filter(
            self::$created,
            static fn (string $createdWith, string $name): bool => ($prefix === null || $createdWith === $prefix) && str_starts_with($name, $createdWith),
            ARRAY_FILTER_USE_BOTH
        ));
        if ($names === []) {
            return;
        }
        try {
            $admin = self::adminConnection();
            foreach ($names as $name) {
                $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
                unset(self::$created[$name]);
            }
        } catch (\Throwable) {
        }
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        if ($this->captureParams) {
            $this->captured[] = ['sql' => $sql, 'params' => $params];
        }
        // prepare() hängt den SQL-Text an $log an und legt die Parameter unter demselben Index ab.
        $this->pendingParams = array_values($params);

        return parent::execute($sql, $params);
    }

    /** Lief die Anweisung `$log[$index]` innerhalb einer Transaktion? (`false` für unbekannte Indizes.) */
    public function txAt(int $index): bool
    {
        return isset($this->log[$index]) && ($this->logTx[$index] ?? false);
    }

    /**
     * Gebundene Parameter der Anweisung `$log[$index]` (`[]` für unbekannte Indizes und für Anweisungen ohne `execute()`).
     *
     * @return list<mixed>
     */
    public function paramsAt(int $index): array
    {
        return isset($this->log[$index]) ? ($this->logParams[$index] ?? []) : [];
    }

    public function prepare(string $sql): \PDOStatement|false
    {
        $this->logTx[count($this->log)] = $this->getPdo()->inTransaction();
        $this->logParams[count($this->log)] = $this->pendingParams ?? [];
        $this->pendingParams = null;
        $this->log[] = $sql;
        $this->statementCount++;
        if ($this->failAtStatement !== null && $this->statementCount === $this->failAtStatement) {
            throw new \PDOException('Testfehler (Injektion bei Anweisung ' . $this->failAtStatement . '): ' . $sql);
        }
        foreach ($this->failOn as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                throw new \PDOException('Testfehler (Injektion): ' . $pattern);
            }
        }

        foreach ($this->throwOn as $pattern => $exception) {
            if (preg_match($pattern, $sql) === 1) {
                throw $exception;
            }
        }

        return parent::prepare($sql);
    }

    private static function adminConnection(): \PDO
    {
        return new \PDO(
            'mysql:host=' . getenv('CMS_TEST_DB_HOST') . ';charset=utf8mb4',
            (string) getenv('CMS_TEST_DB_USER'),
            (string) getenv('CMS_TEST_DB_PASS'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }
}
