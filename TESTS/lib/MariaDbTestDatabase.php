<?php
/**
 * Echte MariaDB/MySQL als `CMS\Database` für die Community-Suiten (PR #2a), ohne Singleton.
 *
 * Der Konstruktor von `CMS\Database` ist privat und das Feld `$pdo` privat; die Klasse setzt es per Reflection
 * (PHP 8.1 oder neuer, ohne `setAccessible()`). So laufen `execute()`, `insert()`, `update()`, `getPdo()` und
 * `lastInsertId()` des Cores unverändert gegen die Verbindung. Optional werfen `execute()`/`prepare()` bei Treffern
 * in `$failOn` (Fehlerinjektion für Rücknahme-Tests).
 *
 * Jeder Aufruf von `create()` legt eine frische Datenbank mit dem Präfix `pr2a_` an (Tabellen aus den echten
 * CREATE-Texten wie das SQLite-Double, Tabellenpräfix `cms_`) und stellt die Sitzung auf die Zone `+01:00`, damit
 * `NOW()`, `CURRENT_TIMESTAMP` und Spaltendefaults (Ortszeit) von den UTC-Werten der Dienste abweichen und auffallen.
 * Alle angelegten Datenbanken werden am Ende des Prozesses gelöscht (auch nach `exit`).
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

use CMS\Database;

final class MariaDbTestDatabase extends Database
{
    use CommunityFixtures;

    /** Präfix aller Test-Datenbanken; aufgeräumt wird nur, was dieser Prozess selbst angelegt hat. */
    public const DATABASE_PREFIX = 'pr2a_';

    /** @var list<string> Reguläre Ausdrücke: passt einer auf das SQL, wirft `prepare()` eine `PDOException`. */
    public array $failOn = [];
    /** @var array<string, \Throwable> Regulärer Ausdruck => Ausnahme: passt der Ausdruck auf das SQL, wirft `prepare()` genau diese. */
    public array $throwOn = [];
    /** @var list<string> Alle vorbereiteten SQL-Texte. */
    public array $log = [];
    public string $databaseName = '';

    /** @var array<string, true> */
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

    /**
     * Legt eine frische Datenbank `pr2a_<pid>_<n>` mit allen Testtabellen an.
     *
     * @param string $sessionTimeZone Offset der Sitzung (wie `Database::connect()` setzt, Standard `+01:00`)
     */
    public static function create(string $sessionTimeZone = '+01:00'): self
    {
        if (!self::configured()) {
            throw new \RuntimeException(self::skipNotice());
        }
        $name = self::DATABASE_PREFIX . getmypid() . '_' . (++self::$counter);
        $admin = self::adminConnection();
        $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
        $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::$created[$name] = true;
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

    /** Löscht alle von diesem Prozess angelegten Test-Datenbanken (Shutdown-Funktion). */
    public static function dropAll(): void
    {
        if (self::$created === []) {
            return;
        }
        try {
            $admin = self::adminConnection();
            foreach (array_keys(self::$created) as $name) {
                if (str_starts_with($name, self::DATABASE_PREFIX)) {
                    $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
                }
            }
        } catch (\Throwable) {
        }
        self::$created = [];
    }

    public function prepare(string $sql): \PDOStatement|false
    {
        $this->log[] = $sql;
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
