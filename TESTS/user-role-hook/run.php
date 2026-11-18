<?php
/**
 * Core-Hook `user_role_changed` (4.2.0, PR #2a): Auslösung bei Rollenwechseln in `UserService::updateUser()`
 * (`admin_update`), `UserService::bulkAction('change_role')` (`admin_bulk`), `RolesModule::updateRole()`
 * (`role_renamed`) und `RolesModule::deleteRole()` (`role_deleted`), dazu die gemeinsame Hilfe
 * `UserService::announceRoleChange()` (nur bei echtem Wechsel, Vergleich ohne Groß-/Kleinschreibung, Ausnahmeschutz).
 *
 * Das Verhalten läuft wirklich: gegen eine SQLite-Attrappe im Speicher (immer) und gegen eine echte MariaDB
 * (mit CMS_TEST_DB_HOST und CMS_TEST_DB_USER; Datenbank `pr2a_<pid>_urh`, wird am Ende gelöscht), dort zusätzlich der
 * Nachweis, dass `SELECT … FOR UPDATE` die Konten bis zum Commit festhält. Geprüft werden Randfälle der Karte
 * (unverändert/Groß-Kleinschreibung, Fehler vor dem Schreiben, Rollback, Rolle ohne Konten, werfender Verbraucher).
 * Quelltext-Prüfungen (Auslösestellen, Reihenfolge, Doku, Schreibstellen auf `users.role`) laufen mit Gegenprobe auf
 * schlechten Beispieltexten. Die Gegenprobe der Suite selbst: Mutanten der beiden Quelldateien (ein Text ersetzt),
 * jede Mutante lässt die Suite in einem eigenen Prozess scheitern (Exit 1), sonst ist die Suite zu schwach.
 *
 * Aufruf aus dem Repository-Wurzelverzeichnis: php TESTS/user-role-hook/run.php [-v]
 * Mit Datenbank: CMS_TEST_DB_HOST=localhost CMS_TEST_DB_USER=root php TESTS/user-role-hook/run.php
 */

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__, 2) . '/CMS/');
define('CMS_DEBUG', false);
define('LOG_LEVEL', 'warning');
define('LOG_PATH', sys_get_temp_dir() . '/cms-tests-logs-urh-' . getmypid() . '/');

date_default_timezone_set('Europe/Berlin');
ini_set('error_log', '/dev/null');

/**
 * Lädt eine Quelldatei des Cores; läuft die Suite als Mutanten-Prozess (CMS_TEST_MUTANT), mit den Ersetzungen der Mutante.
 * Nicht betroffene Dateien werden unverändert eingebunden.
 */
function urhRequire(string $relative): void
{
    $path = ABSPATH . $relative;
    $name = (string) getenv('CMS_TEST_MUTANT');
    $mutant = $name !== '' ? (urhMutants()[$name] ?? null) : null;
    if ($name !== '' && $mutant === null) {
        fwrite(STDERR, 'Unbekannte Mutante: ' . $name . PHP_EOL);
        exit(99);
    }
    if ($mutant === null || $mutant['file'] !== $relative) {
        require_once $path;

        return;
    }
    $source = (string) file_get_contents($path);
    foreach ($mutant['edits'] as [$search, $replace]) {
        if (substr_count($source, $search) !== 1) {
            fwrite(STDERR, 'Mutante ' . $name . ': Suchtext kommt nicht genau einmal vor: ' . substr($search, 0, 120) . PHP_EOL);
            exit(99);
        }
        $source = str_replace($search, $replace, $source);
    }
    $directory = sys_get_temp_dir() . '/cms-urh-mutant-' . getmypid();
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
        register_shutdown_function(static function () use ($directory): void {
            array_map('unlink', glob($directory . '/*') ?: []);
            @rmdir($directory);
        });
    }
    $copy = $directory . '/' . basename($relative);
    file_put_contents($copy, $source);
    require_once $copy;
}

spl_autoload_register(static function (string $class): void {
    if ($class === 'CMS\\Services\\UserService') {
        urhRequire('core/Services/UserService.php');
    }
}, true, true);
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'CMS\\')) {
        return;
    }
    $file = ABSPATH . 'core/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once dirname(__DIR__) . '/lib/TestRunner.php';

use CMS\Database;
use CMS\Hooks;
use CMS\Services\UserService;
use CMS\WP_Error;

T::$verbose = in_array('-v', $argv ?? [], true);

/**
 * Mutanten: je Eintrag ein Verhalten der Auslösestellen, das die Suite sichern muss. `edits` sind (Suchtext, Ersatz)
 * in Reihenfolge, jeder Suchtext muss in der Datei genau einmal vorkommen (sonst Exit 99). `db` = braucht die echte MariaDB.
 *
 * @return array<string, array{file: string, rule: string, edits: list<array{0: string, 1: string}>, db?: bool}>
 */
function urhMutants(): array
{
    $users = 'core/Services/UserService.php';
    $roles = 'admin/modules/users/RolesModule.php';
    $updateCall = "self::announceRoleChange(\$user_id, (string) (\$user->role ?? ''), (string) \$update_data['role'], self::ROLE_SOURCE_ADMIN_UPDATE);";
    $loggerCall = "Logger::instance()->withChannel('user.service')->warning('Ein Verbraucher von user_role_changed ist fehlgeschlagen.', [";
    $renameCall = "\$this->announceRoleChanges(\$affectedUserIds, \$currentRole, \$newRole, UserService::ROLE_SOURCE_ROLE_RENAMED);";
    $deleteCall = "\$this->announceRoleChanges(\$affectedUserIds, \$role, \$fallbackRole, UserService::ROLE_SOURCE_ROLE_DELETED);";

    return [
        'update-ohne-meldung' => ['file' => $users, 'rule' => 'updateUser meldet den Wechsel', 'edits' => [[$updateCall, '']]],
        'update-meldung-vor-schreiben' => ['file' => $users, 'rule' => 'updateUser meldet erst nach dem erfolgreichen Schreiben', 'edits' => [
            [$updateCall, ''],
            ['// Update nur wenn Daten vorhanden', "if (isset(\$update_data['role'])) { " . $updateCall . " }\n        // Update nur wenn Daten vorhanden"],
        ]],
        'unveraendert-meldet' => ['file' => $users, 'rule' => 'unveränderte Rolle meldet nichts', 'edits' => [['strcasecmp($fromRole, $toRole) === 0', 'false']]],
        'gross-klein-zaehlt' => ['file' => $users, 'rule' => 'Groß-/Kleinschreibung ist kein Wechsel', 'edits' => [['strcasecmp($fromRole, $toRole) === 0', '$fromRole === $toRole']]],
        'verbraucher-wirft-durch' => ['file' => $users, 'rule' => 'werfender Verbraucher kippt das Ergebnis nicht', 'edits' => [[$loggerCall, 'throw $e; ' . $loggerCall]]],
        'verbraucher-fehler-nicht-protokolliert' => ['file' => $users, 'rule' => 'Verbraucherfehler wird protokolliert', 'edits' => [[$loggerCall, "(static fn (...\$arguments) => null)('Ein Verbraucher von user_role_changed ist fehlgeschlagen.', ["]]],
        'bulk-ohne-meldung' => ['file' => $users, 'rule' => 'Sammelaktion meldet je Konto', 'edits' => [['self::announceRoleChange($userId, (string) $from, $role, $source);', '']]],
        'bulk-unbekanntes-konto-gelingt' => ['file' => $users, 'rule' => 'unbekannte Konto-ID zählt als Fehler', 'edits' => [[
            "if (\$from === null || !\$this->db->update('users', ['role' => \$role], ['id' => \$userId])) {",
            "if (!\$this->db->update('users', ['role' => \$role], ['id' => \$userId])) {",
        ]]],
        'umbenennen-ohne-meldung' => ['file' => $roles, 'rule' => 'Umbenennen meldet je Konto', 'edits' => [[$renameCall, '']]],
        'umbenennen-meldung-vor-commit' => ['file' => $roles, 'rule' => 'Umbenennen meldet erst nach dem Commit', 'edits' => [
            [$renameCall, ''],
            ['if ($permissionStatement->rowCount() < 1 && $userStatement->rowCount() < 1) {', $renameCall . "\n            " . 'if ($permissionStatement->rowCount() < 1 && $userStatement->rowCount() < 1) {'],
        ]],
        'loeschen-ohne-meldung' => ['file' => $roles, 'rule' => 'Löschen meldet je Konto', 'edits' => [[$deleteCall, '']]],
        'loeschen-meldung-vor-fehler' => ['file' => $roles, 'rule' => 'Fehler in der Transaktion melden nichts', 'edits' => [
            [$deleteCall, ''],
            ["\$permissionStatement = \$this->db->execute(\n                \"DELETE FROM", $deleteCall . "\n            \$permissionStatement = \$this->db->execute(\n                \"DELETE FROM"],
        ]],
        'loeschen-falsche-ersatzrolle' => ['file' => $roles, 'rule' => 'Löschen meldet die tatsächliche Ersatzrolle', 'edits' => [[$deleteCall, "\$this->announceRoleChanges(\$affectedUserIds, \$role, 'member', UserService::ROLE_SOURCE_ROLE_DELETED);"]]],
        'konten-ohne-sperre' => ['file' => $roles, 'rule' => 'FOR UPDATE hält die Konten bis zum Commit fest', 'db' => true, 'edits' => [[' ORDER BY id ASC FOR UPDATE', ' ORDER BY id ASC']]],
    ];
}

urhRequire('admin/modules/users/RolesModule.php');

// ── Datenbank-Doubles ───────────────────────────────────────────────────────────────────────────────────

/** Fehlerinjektion für beide Doubles: `failOn` (reguläre Ausdrücke auf das SQL), `failUpdates`, `beforeExecute`. */
trait UrhFaults
{
    public bool $failUpdates = false;
    /** @var list<string> */
    public array $failOn = [];
    /** @var null|\Closure(string): void */
    public ?\Closure $beforeExecute = null;

    public function resetFaults(): void
    {
        $this->failUpdates = false;
        $this->failOn = [];
        $this->beforeExecute = null;
    }

    private function intercept(string $sql): void
    {
        if ($this->beforeExecute !== null) {
            ($this->beforeExecute)($sql);
        }
        foreach ($this->failOn as $pattern) {
            if (preg_match($pattern, $sql) === 1) {
                throw new \PDOException('Fehlerinjektion: ' . $pattern);
            }
        }
    }

    private function simulatedWriteFailure(): bool
    {
        $this->last_error = 'simulierter Schreibfehler';

        return false;
    }
}

/** PDO, das die MySQL-spezifischen CREATE-TABLE-Anweisungen von `RolesModule::ensureTable()` ignoriert (SQLite kennt ENGINE=…). */
final class UrhSqlitePdo extends \PDO
{
    public function exec(string $statement): int|false
    {
        return str_starts_with(ltrim($statement), 'CREATE TABLE IF NOT EXISTS') ? 0 : parent::exec($statement);
    }
}

/** SQLite im Speicher als `CMS\Database`; `FOR UPDATE` wird vor der Ausführung entfernt (SQLite sperrt ohnehin die ganze Datei). */
final class UrhSqliteDatabase extends Database
{
    use UrhFaults;

    public \PDO $sqlite;

    public function __construct()
    {
        $this->sqlite = new UrhSqlitePdo('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->sqlite->exec("CREATE TABLE cms_users (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE, email TEXT UNIQUE, password TEXT, display_name TEXT,
            role TEXT NOT NULL DEFAULT 'member', status TEXT NOT NULL DEFAULT 'active', created_at TEXT, updated_at TEXT, last_login TEXT)");
        $this->sqlite->exec('CREATE TABLE cms_user_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, meta_key TEXT, meta_value TEXT)');
        $this->sqlite->exec('CREATE TABLE cms_role_permissions (id INTEGER PRIMARY KEY AUTOINCREMENT, role TEXT, capability TEXT, granted INTEGER DEFAULT 0, UNIQUE (role, capability))');
        $this->sqlite->exec('CREATE TABLE cms_activity_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, entity_type TEXT, entity_id INTEGER,
            description TEXT, ip_address TEXT, user_agent TEXT, metadata TEXT)');
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $this->intercept($sql);
        $statement = $this->sqlite->prepare(str_replace(' FOR UPDATE', '', $sql));
        $statement->execute(array_values($params));

        return $statement;
    }

    public function insert(string $table, array $data): int|bool
    {
        $keys = array_keys($data);
        $this->execute('INSERT INTO cms_' . $table . ' (' . implode(', ', $keys) . ') VALUES (' . implode(', ', array_fill(0, count($keys), '?')) . ')', array_values($data));

        return (int) $this->sqlite->lastInsertId();
    }

    public function update(string $table, array $data, array $where): bool
    {
        if ($this->failUpdates) {
            return $this->simulatedWriteFailure();
        }
        $set = implode(', ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($data)));
        $condition = implode(' AND ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($where)));
        $this->execute('UPDATE cms_' . $table . ' SET ' . $set . ' WHERE ' . $condition, [...array_values($data), ...array_values($where)]);

        return true;
    }

    public function getPdo(): \PDO
    {
        return $this->sqlite;
    }
}

/** Echte MariaDB/MySQL als `CMS\Database` ohne Singleton (privater Konstruktor der Elternklasse, `$pdo` per Reflection gesetzt). */
final class UrhMariaDatabase extends Database
{
    use UrhFaults;

    public function __construct(\PDO $pdo, public readonly string $databaseName)
    {
        (new \ReflectionProperty(Database::class, 'pdo'))->setValue($this, $pdo);
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $this->intercept($sql);

        return parent::execute($sql, $params);
    }

    public function update(string $table, array $data, array $where): bool
    {
        return $this->failUpdates ? $this->simulatedWriteFailure() : parent::update($table, $data, $where);
    }
}

function urhMariaConnect(string $database = ''): \PDO
{
    return new \PDO(
        'mysql:host=' . getenv('CMS_TEST_DB_HOST') . ($database !== '' ? ';dbname=' . $database : '') . ';charset=utf8mb4',
        (string) getenv('CMS_TEST_DB_USER'),
        (string) getenv('CMS_TEST_DB_PASS'),
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ, \PDO::ATTR_EMULATE_PREPARES => false]
    );
}

function urhMariaConfigured(): bool
{
    return (string) getenv('CMS_TEST_DB_HOST') !== '' && (string) getenv('CMS_TEST_DB_USER') !== '' && extension_loaded('pdo_mysql');
}

/** Legt (einmal je Prozess) die Datenbank `pr2a_<pid>_urh` mit den echten Tabellen `users`, `user_meta`, `activity_log` an und löscht sie am Ende. */
function urhMariaDatabase(): UrhMariaDatabase
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    $name = 'pr2a_' . getmypid() . '_urh';
    $admin = urhMariaConnect();
    $admin->exec("DROP DATABASE IF EXISTS `{$name}`");
    $admin->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    register_shutdown_function(static function () use ($name): void {
        try {
            urhMariaConnect()->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (\Throwable) {
        }
    });
    $pdo = urhMariaConnect($name);
    $queries = \CMS\SchemaManager::getSchemaQueries('cms_', 'utf8mb4');
    foreach (['users', 'user_meta', 'activity_log'] as $table) {
        $pdo->exec($queries[$table]);
    }
    $db = new UrhMariaDatabase($pdo, $name);

    return $db;
}

// ── Fixture ──────────────────────────────────────────────────────────────────────────────────────────────

/** Eine frische Welt je Test: Datenbank, Dienste mit leerem Singleton-Zustand, Hook-Beobachter. */
final class UrhFixture
{
    /** @var \ArrayObject<int, array{0: int, 1: string, 2: string, 3: string}> */
    public \ArrayObject $events;
    /** @var \ArrayObject<int, string> Rolle des Kontos in der Datenbank, als das Ereignis eintraf */
    public \ArrayObject $roleAtEvent;
    /** @var \ArrayObject<int, bool> lief beim Eintreffen eine Transaktion? */
    public \ArrayObject $inTransaction;
    public Database $db;
    public UserService $service;
    public \RolesModule $roles;

    /**
     * @param array<int, string> $users Konto-ID → Rolle
     * @param list<string> $permissionRoles Rollen mit einer Rechtezeile (`role_permissions`)
     */
    public function __construct(string $backend, array $users, array $permissionRoles = [])
    {
        $db = $backend === 'mariadb' ? urhMariaDatabase() : new UrhSqliteDatabase();
        $db->resetFaults();
        $this->db = $db;
        (new \ReflectionProperty(Database::class, 'instance'))->setValue(null, $db);
        (new \ReflectionProperty(Hooks::class, 'actions'))->setValue(null, []);
        $this->roles = new \RolesModule();
        if ($backend === 'mariadb') {
            (new \ReflectionMethod(\RolesModule::class, 'ensureTable'))->invoke($this->roles);
            foreach (['user_meta', 'users', 'role_permissions', 'activity_log'] as $table) {
                $db->getPdo()->exec('DELETE FROM cms_' . $table);
            }
        }
        $insert = $db->getPdo()->prepare("INSERT INTO cms_users (id, username, email, password, display_name, role, status) VALUES (?, ?, ?, 'x', ?, ?, 'active')");
        foreach ($users as $id => $role) {
            $insert->execute([$id, 'user' . $id, 'u' . $id . '@example.org', 'U' . $id, $role]);
        }
        $permission = $db->getPdo()->prepare("INSERT INTO cms_role_permissions (role, capability, granted) VALUES (?, 'read', 1)");
        foreach ($permissionRoles as $role) {
            $permission->execute([$role]);
        }
        $this->service = new UserService();

        $this->events = new \ArrayObject();
        $this->roleAtEvent = new \ArrayObject();
        $this->inTransaction = new \ArrayObject();
        $events = $this->events;
        $roleAtEvent = $this->roleAtEvent;
        $inTransaction = $this->inTransaction;
        Hooks::addAction('user_role_changed', static function (...$arguments) use ($db, $events, $roleAtEvent, $inTransaction): void {
            $events[] = $arguments;
            $statement = $db->getPdo()->prepare('SELECT role FROM cms_users WHERE id = ?');
            $statement->execute([$arguments[0]]);
            $roleAtEvent[] = (string) $statement->fetchColumn();
            $inTransaction[] = $db->getPdo()->inTransaction();
        });
        urhClearLog();
    }

    /** Ein Verbraucher, der immer wirft (Priorität 20: der Beobachter davor hat das Ereignis schon). */
    public function addThrowingConsumer(): void
    {
        Hooks::addAction('user_role_changed', static function (): void {
            throw new \RuntimeException('Verbraucher kaputt');
        }, 20);
    }

    /** @return array<int, string> Konto-ID → Rolle */
    public function accountRoles(): array
    {
        return $this->db->getPdo()->query('SELECT id, role FROM cms_users ORDER BY id')->fetchAll(\PDO::FETCH_KEY_PAIR);
    }

    /** @return list<string> */
    public function permissionRoles(): array
    {
        return $this->db->getPdo()->query('SELECT DISTINCT role FROM cms_role_permissions ORDER BY role')->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function status(int $id): string
    {
        $statement = $this->db->getPdo()->prepare('SELECT status FROM cms_users WHERE id = ?');
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }

    /** @return list<array{0: int, 1: string, 2: string, 3: string}> */
    public function events(): array
    {
        return $this->events->getArrayCopy();
    }

    public function clearEvents(): void
    {
        $this->events->exchangeArray([]);
        $this->roleAtEvent->exchangeArray([]);
        $this->inTransaction->exchangeArray([]);
    }
}

function urhLogText(): string
{
    $text = '';
    foreach (glob(LOG_PATH . '*.log') ?: [] as $file) {
        $text .= (string) file_get_contents($file);
    }

    return $text;
}

function urhClearLog(): void
{
    foreach (glob(LOG_PATH . '*.log') ?: [] as $file) {
        unlink($file);
    }
}

register_shutdown_function(static function (): void {
    foreach (glob(LOG_PATH . '*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir(LOG_PATH);
});

// ── Verhalten (läuft je Backend) ────────────────────────────────────────────────────────────────────────

function urhBehaviour(string $backend, string $label): void
{
    $same = static fn (mixed $expected, mixed $actual, string $message): bool => T::same($expected, $actual, $message);

    T::run("[{$label}] updateUser meldet den Rollenwechsel", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'member', 2 => 'editor']);

        $same(true, $f->service->updateUser(1, ['role' => 'editor']), 'updateUser liefert true');
        $same([[1, 'member', 'editor', 'admin_update']], $f->events(), 'genau ein Ereignis (Konto, von, nach, Quelle)');
        $same(['editor'], $f->roleAtEvent->getArrayCopy(), 'Ereignis erst nach dem Schreiben (Rolle steht schon in der Datenbank)');
        $same([false], $f->inTransaction->getArrayCopy(), 'ohne offene Transaktion');
        $same([1 => 'editor', 2 => 'editor'], $f->accountRoles(), 'Rolle gespeichert');

        $f->clearEvents();
        $f->service->updateUser(1, ['role' => 'EDITOR']);
        $same([], $f->events(), 'unveränderte Rolle (anders geschrieben): kein Ereignis');

        $f->service->updateUser(2, ['status' => 'banned']);
        $same([[], 'banned'], [$f->events(), $f->status(2)], 'nur Status geändert: kein Ereignis');

        $f->service->updateUser(2, ['first_name' => 'Erika']);
        $same([], $f->events(), 'Rolle nicht übergeben: kein Ereignis');

        $f->service->updateUser(2, ['role' => 'gibt-es-nicht']);
        $same([[2, 'editor', 'member', 'admin_update']], $f->events(), 'unbekannte Rolle fällt auf member zurück: Ereignis mit der tatsächlich geschriebenen Rolle');

        $f->clearEvents();
        $result = $f->service->updateUser(1, ['role' => 'author', 'status' => 'inactive', 'display_name_ignored' => 'x']);
        $same([true, [[1, 'editor', 'author', 'admin_update']], 'inactive'], [$result, $f->events(), $f->status(1)], 'Rolle und Status zugleich: ein Ereignis');
    });

    T::run("[{$label}] updateUser: Fehler vor oder beim Schreiben melden nichts", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'member', 2 => 'member']);

        $result = $f->service->updateUser(99, ['role' => 'editor']);
        $same([true, 'user_not_found', []], [$result instanceof WP_Error, $result instanceof WP_Error ? $result->get_error_code() : '', $f->events()], 'unbekanntes Konto: Fehler, kein Ereignis');

        $result = $f->service->updateUser(1, ['role' => 'editor', 'email' => 'u2@example.org']);
        $same(['email_exists', [], [1 => 'member', 2 => 'member']], [$result instanceof WP_Error ? $result->get_error_code() : '', $f->events(), $f->accountRoles()], 'E-Mail schon vergeben: Fehler vor dem Schreiben, keine Rolle geändert, kein Ereignis');

        $result = $f->service->updateUser(1, ['role' => 'editor', 'email' => 'keine-adresse']);
        $same(['invalid_email', [], [1 => 'member', 2 => 'member']], [$result instanceof WP_Error ? $result->get_error_code() : '', $f->events(), $f->accountRoles()], 'ungültige Eingabe: Fehler, kein Ereignis');

        $f->db->failUpdates = true;
        $result = $f->service->updateUser(1, ['role' => 'author']);
        $same(['db_error', [], [1 => 'member', 2 => 'member']], [$result instanceof WP_Error ? $result->get_error_code() : '', $f->events(), $f->accountRoles()], 'Schreibfehler: Fehler, kein Ereignis, Rolle unverändert');
    });

    T::run("[{$label}] Ein werfender Verbraucher macht die gespeicherte Änderung nicht zum Fehler", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'member']);
        $f->addThrowingConsumer();

        $same(true, $f->service->updateUser(1, ['role' => 'author']), 'updateUser bleibt true');
        $same([1 => 'author'], $f->accountRoles(), 'Rolle gespeichert');
        $same([[1, 'member', 'author', 'admin_update']], $f->events(), 'der frühere Verbraucher hat das Ereignis erhalten');
        $log = urhLogText();
        $same(
            [true, true, true, true, true],
            [
                str_contains($log, '[user.service]'),
                str_contains($log, 'Ein Verbraucher von user_role_changed ist fehlgeschlagen'),
                str_contains($log, 'RuntimeException: Verbraucher kaputt'),
                str_contains($log, '"user_id":1'),
                str_contains($log, '"source":"admin_update"'),
            ],
            'Fehler im Protokoll (Kanal user.service, Konto, Quelle, Ausnahme)'
        );
    });

    T::run("[{$label}] bulkAction(change_role)", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'member', 2 => 'author', 3 => 'editor']);

        $result = $f->service->bulkAction('change_role', [1, 2, 3, 99], ['role' => 'editor']);
        $same([3, 1], [$result['success'], $result['failed']], 'Konto 1 und 2 geändert, 3 schon Redakteur (Erfolg ohne Ereignis), 99 unbekannt: Fehler');
        $same(true, str_contains(implode(' ', $result['errors']), 'User ID 99'), 'Fehlertext nennt das unbekannte Konto');
        $same([[1, 'member', 'editor', 'admin_bulk'], [2, 'author', 'editor', 'admin_bulk']], $f->events(), 'Ereignisse nur für tatsächliche Wechsel, Quelle admin_bulk');
        $same(['editor', 'editor'], $f->roleAtEvent->getArrayCopy(), 'Ereignisse erst nach dem Schreiben');
        $same([1 => 'editor', 2 => 'editor', 3 => 'editor'], $f->accountRoles(), 'Rollen gespeichert, Konto 99 wurde nicht angelegt');

        $f->clearEvents();
        $result = $f->service->bulkAction('change_role', [1], []);
        $same([0, 1, []], [$result['success'], $result['failed'], $f->events()], 'ohne Rolle: Fehler, kein Ereignis');
        $result = $f->service->bulkAction('change_role', [1], ['role' => '  ']);
        $same([0, 1, []], [$result['success'], $result['failed'], $f->events()], 'leere Rolle: Fehler, kein Ereignis');

        $result = $f->service->bulkAction('change_role', [2], ['role' => 'gibt-es-nicht']);
        $same([1, [[2, 'editor', 'member', 'admin_bulk']]], [$result['success'], $f->events()], 'unbekannte Rolle fällt auf member zurück: Ereignis mit der tatsächlichen Rolle');

        $f->clearEvents();
        $f->service->bulkAction('activate', [1, 2]);
        $f->service->bulkAction('deactivate', [1, 2]);
        $same([[], 'inactive'], [$f->events(), $f->status(1)], 'activate/deactivate lösen kein Rollen-Ereignis aus');

        $_SESSION['user_id'] = 3;
        try {
            $result = $f->service->bulkAction('change_role', [3, 1], ['role' => 'author']);
        } finally {
            unset($_SESSION['user_id']);
        }
        $same([1, 1, [[1, 'editor', 'author', 'admin_bulk']], 'editor'], [$result['success'], $result['failed'], $f->events(), $f->accountRoles()[3]], 'eigenes Konto bleibt unberührt, kein Ereignis dafür');

        $f->clearEvents();
        $f->db->failUpdates = true;
        $result = $f->service->bulkAction('change_role', [1], ['role' => 'member']);
        $same([0, 1, [], 'author'], [$result['success'], $result['failed'], $f->events(), $f->accountRoles()[1]], 'Schreibfehler: Fehler, kein Ereignis, Rolle unverändert');
    });

    T::run("[{$label}] bulkAction: werfender Verbraucher", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'member', 2 => 'member', 3 => 'member']);
        $f->addThrowingConsumer();

        $result = $f->service->bulkAction('change_role', [1, 2, 3], ['role' => 'editor']);
        $same([3, 0], [$result['success'], $result['failed']], 'alle Konten gelungen, obwohl der Verbraucher wirft');
        $same([1 => 'editor', 2 => 'editor', 3 => 'editor'], $f->accountRoles(), 'alle Rollen gespeichert');
        $same([1, 2, 3], array_column($f->events(), 0), 'jedes Konto meldet einzeln (ein Fehler bricht die Folgekonten nicht ab)');
        $same(3, substr_count(urhLogText(), 'Ein Verbraucher von user_role_changed ist fehlgeschlagen'), 'je Konto ein Protokolleintrag');
    });

    T::run("[{$label}] RolesModule::updateRole meldet je Konto nach dem Commit", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'custom', 2 => 'custom', 3 => 'member', 4 => 'custom'], ['custom']);

        $result = $f->roles->updateRole(['current_role' => 'custom', 'role_slug' => 'partner']);
        $same(true, $result['success'] ?? null, 'Umbenennen gelingt: ' . ($result['error'] ?? ''));
        $same(
            [[1, 'custom', 'partner', 'role_renamed'], [2, 'custom', 'partner', 'role_renamed'], [4, 'custom', 'partner', 'role_renamed']],
            $f->events(),
            'ein Ereignis je betroffenem Konto in Konto-Reihenfolge, Konto 3 (andere Rolle) nicht'
        );
        $same(['partner', 'partner', 'partner'], $f->roleAtEvent->getArrayCopy(), 'beim Ereignis steht die neue Rolle schon in der Datenbank');
        $same([false, false, false], $f->inTransaction->getArrayCopy(), 'beim Ereignis ist die Transaktion schon abgeschlossen (nach dem Commit)');
        $same([1 => 'partner', 2 => 'partner', 3 => 'member', 4 => 'partner'], $f->accountRoles(), 'Konten tragen den neuen Namen');
        $same(['partner'], $f->permissionRoles(), 'Rechtezeilen wandern mit');

        $f->clearEvents();
        $result = $f->roles->updateRole(['current_role' => 'partner', 'role_slug' => 'partner']);
        $same([true, []], [$result['success'] ?? null, $f->events()], 'gleicher Name: kein Ereignis');

        foreach ([['member', 'x', 'Systemrolle'], ['gibt-es-nicht', 'y', 'unbekannte Rolle'], ['partner', 'editor', 'Zielrolle existiert']] as [$from, $to, $label2]) {
            $result = $f->roles->updateRole(['current_role' => $from, 'role_slug' => $to]);
            $same([false, []], [$result['success'] ?? null, $f->events()], $label2 . ': abgelehnt, kein Ereignis');
        }
        $same([1 => 'partner', 2 => 'partner', 3 => 'member', 4 => 'partner'], $f->accountRoles(), 'abgelehnte Aufrufe ändern keine Rolle');
    });

    T::run("[{$label}] RolesModule::updateRole: Rolle ohne Konten und Fehler in der Transaktion", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'member', 2 => 'alt', 3 => 'alt'], ['leer', 'alt']);

        $result = $f->roles->updateRole(['current_role' => 'leer', 'role_slug' => 'leer2']);
        $same([true, [], ['alt', 'leer2']], [$result['success'] ?? null, $f->events(), $f->permissionRoles()], 'Rolle ohne Konten: nur die Rechtezeilen wandern, kein Ereignis');

        // Das Umhängen der Konten scheitert, nachdem die Rechtezeilen schon umbenannt sind: Rollback, kein Ereignis.
        $f->db->failOn = ['/UPDATE\s+\S*users\s+SET\s+role/'];
        $result = $f->roles->updateRole(['current_role' => 'alt', 'role_slug' => 'neu']);
        $same([false, []], [$result['success'] ?? null, $f->events()], 'Fehler im Rahmen der Transaktion: kein Ereignis');
        $same([[1 => 'member', 2 => 'alt', 3 => 'alt'], ['alt', 'leer2'], false], [$f->accountRoles(), $f->permissionRoles(), $f->db->getPdo()->inTransaction()], 'Rollback: Konten und Rechtezeilen unverändert, keine offene Transaktion');

        // Fehler schon beim Auswählen der Konten.
        $f->db->failOn = ['/FOR UPDATE/'];
        $result = $f->roles->updateRole(['current_role' => 'alt', 'role_slug' => 'neu']);
        $same([false, [], ['alt', 'leer2']], [$result['success'] ?? null, $f->events(), $f->permissionRoles()], 'Fehler beim Auswählen der Konten: nichts geändert, kein Ereignis');
        $f->db->failOn = [];

        // Nach einem Fehler läuft derselbe Aufruf wieder durch.
        $result = $f->roles->updateRole(['current_role' => 'alt', 'role_slug' => 'neu']);
        $same([true, [[2, 'alt', 'neu', 'role_renamed'], [3, 'alt', 'neu', 'role_renamed']]], [$result['success'] ?? null, $f->events()], 'erneuter Aufruf nach dem Fehler meldet beide Konten');
    });

    T::run("[{$label}] RolesModule::updateRole: werfender Verbraucher", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'custom', 2 => 'custom', 3 => 'custom'], ['custom']);
        $f->addThrowingConsumer();

        $result = $f->roles->updateRole(['current_role' => 'custom', 'role_slug' => 'partner']);
        $same(true, $result['success'] ?? null, 'Ergebnis bleibt „gelungen“: ' . ($result['error'] ?? ''));
        $same([[1 => 'partner', 2 => 'partner', 3 => 'partner'], ['partner']], [$f->accountRoles(), $f->permissionRoles()], 'Änderung bleibt gespeichert');
        $same([1, 2, 3], array_column($f->events(), 0), 'jedes Konto meldet, obwohl ein Verbraucher wirft');
        $same(3, substr_count(urhLogText(), '"source":"role_renamed"'), 'je Konto ein Protokolleintrag mit Quelle role_renamed');
    });

    T::run("[{$label}] RolesModule::deleteRole meldet je Konto nach dem Commit", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'partner', 2 => 'partner', 3 => 'member', 4 => 'partner'], ['partner']);

        $result = $f->roles->deleteRole(['role_slug' => 'partner', 'fallback_role' => 'member']);
        $same(true, $result['success'] ?? null, 'Löschen gelingt: ' . ($result['error'] ?? ''));
        $same(
            [[1, 'partner', 'member', 'role_deleted'], [2, 'partner', 'member', 'role_deleted'], [4, 'partner', 'member', 'role_deleted']],
            $f->events(),
            'Konten fallen auf die Ersatzrolle zurück, ein Ereignis je Konto'
        );
        $same(['member', 'member', 'member'], $f->roleAtEvent->getArrayCopy(), 'beim Ereignis steht die Ersatzrolle schon in der Datenbank');
        $same([false, false, false], $f->inTransaction->getArrayCopy(), 'beim Ereignis ist die Transaktion schon abgeschlossen (nach dem Commit)');
        $same([[1 => 'member', 2 => 'member', 3 => 'member', 4 => 'member'], []], [$f->accountRoles(), $f->permissionRoles()], 'alle Konten sind Mitglieder, Rechtezeilen gelöscht');
    });

    T::run("[{$label}] RolesModule::deleteRole: Ersatzrolle", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'partner', 2 => 'member'], ['partner']);

        $result = $f->roles->deleteRole(['role_slug' => 'partner', 'fallback_role' => 'editor']);
        $same([true, [[1, 'partner', 'editor', 'role_deleted']], [1 => 'editor', 2 => 'member']], [$result['success'] ?? null, $f->events(), $f->accountRoles()], 'bestehende Ersatzrolle: Ereignis nennt sie');

        foreach (['', 'partner2', 'gibt-es-nicht'] as $fallback) {
            $f = new UrhFixture($backend, [1 => 'partner2', 2 => 'member'], ['partner2']);
            $result = $f->roles->deleteRole(['role_slug' => 'partner2', 'fallback_role' => $fallback]);
            $same(
                [true, [[1, 'partner2', 'member', 'role_deleted']]],
                [$result['success'] ?? null, $f->events()],
                'Ersatzrolle „' . $fallback . '“ (leer, gleich oder unbekannt): Konten fallen auf member zurück, Ereignis nennt member'
            );
        }
    });

    T::run("[{$label}] RolesModule::deleteRole: Randfälle und Fehler in der Transaktion", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'member', 2 => 'alt'], ['leer', 'alt']);

        $result = $f->roles->deleteRole(['role_slug' => 'leer', 'fallback_role' => 'member']);
        $same([true, [], ['alt']], [$result['success'] ?? null, $f->events(), $f->permissionRoles()], 'Rolle ohne Konten: nur die Rechtezeilen werden gelöscht, kein Ereignis');

        foreach ([['member', 'Systemrolle'], ['gibt-es-nicht', 'unbekannte Rolle']] as [$role, $label2]) {
            $result = $f->roles->deleteRole(['role_slug' => $role, 'fallback_role' => 'member']);
            $same([false, []], [$result['success'] ?? null, $f->events()], $label2 . ': abgelehnt, kein Ereignis');
        }

        // Das Löschen der Rechtezeilen scheitert, nachdem die Konten schon umgehängt sind: Rollback, kein Ereignis.
        $f->db->failOn = ['/DELETE\s+FROM\s+\S*role_permissions/'];
        $result = $f->roles->deleteRole(['role_slug' => 'alt', 'fallback_role' => 'member']);
        $same([false, []], [$result['success'] ?? null, $f->events()], 'Fehler im Rahmen der Transaktion: kein Ereignis');
        $same([[1 => 'member', 2 => 'alt'], ['alt'], false], [$f->accountRoles(), $f->permissionRoles(), $f->db->getPdo()->inTransaction()], 'Rollback: Konto und Rechtezeile unverändert, keine offene Transaktion');
        $f->db->failOn = [];

        $result = $f->roles->deleteRole(['role_slug' => 'alt', 'fallback_role' => 'member']);
        $same([true, [[2, 'alt', 'member', 'role_deleted']]], [$result['success'] ?? null, $f->events()], 'erneuter Aufruf nach dem Fehler meldet das Konto');
    });

    T::run("[{$label}] RolesModule::deleteRole: werfender Verbraucher", static function () use ($backend, $same): void {
        $f = new UrhFixture($backend, [1 => 'partner', 2 => 'partner'], ['partner']);
        $f->addThrowingConsumer();

        $result = $f->roles->deleteRole(['role_slug' => 'partner', 'fallback_role' => 'member']);
        $same([true, [1 => 'member', 2 => 'member'], []], [$result['success'] ?? null, $f->accountRoles(), $f->permissionRoles()], 'Ergebnis „gelungen“, Änderung bleibt gespeichert');
        $same(2, substr_count(urhLogText(), '"source":"role_deleted"'), 'je Konto ein Protokolleintrag mit Quelle role_deleted');
    });
}

// ── announceRoleChange() ───────────────────────────────────────────────────────────────────────────────

T::run('announceRoleChange: Entscheidung, Argumente, Ausnahmeschutz', static function (): void {
    $f = new UrhFixture('sqlite', []);

    $matrix = [
        [1, 'member', 'editor', true, 'Wechsel'],
        [1, '', 'editor', true, 'leere alte Rolle (z. B. unbekanntes Konto) ist ein Wechsel'],
        [1, 'member', 'member', false, 'gleiche Rolle'],
        [1, 'Editor', 'editor', false, 'gleiche Rolle, andere Schreibung (alt groß)'],
        [1, 'editor', 'EDITOR', false, 'gleiche Rolle, andere Schreibung (neu groß)'],
        [1, 'member', '', false, 'leere neue Rolle'],
        [0, 'member', 'editor', false, 'Konto-ID 0'],
        [-5, 'member', 'editor', false, 'negative Konto-ID'],
    ];
    foreach ($matrix as [$id, $from, $to, $expected, $label]) {
        $f->clearEvents();
        $fired = UserService::announceRoleChange($id, $from, $to, 'admin_update');
        T::same([$expected, $expected ? [[$id, $from, $to, 'admin_update']] : []], [$fired, $f->events()], $label . ': Rückgabe und Ereignis');
    }

    $f->clearEvents();
    UserService::announceRoleChange(7, 'a', 'b', 'sso');
    T::same([[7, 'a', 'b', 'sso']], $f->events(), 'Argumente: Konto, von, nach, Quelle unverändert durchgereicht');

    (new \ReflectionProperty(Hooks::class, 'actions'))->setValue(null, []);
    T::same(true, UserService::announceRoleChange(1, 'member', 'editor', 'admin_update'), 'ohne jeden Verbraucher: kein Fehler');

    $f = new UrhFixture('sqlite', []);
    $f->addThrowingConsumer();
    T::same(true, UserService::announceRoleChange(1, 'member', 'editor', 'admin_update'), 'werfender Verbraucher: Rückgabe true, nichts dringt durch');
    $log = urhLogText();
    T::same([true, true], [str_contains($log, 'RuntimeException: Verbraucher kaputt'), str_contains($log, '[WARNING]')], 'Fehler als Warnung protokolliert');

    $f = new UrhFixture('sqlite', []);
    Hooks::addAction('user_role_changed', static function (): void {
        throw new \TypeError('anderer Fehlertyp');
    }, 20);
    T::same(true, UserService::announceRoleChange(1, 'member', 'editor', 'admin_update'), 'auch Error-Typen (TypeError) dringen nicht durch');
    T::same(true, str_contains(urhLogText(), 'TypeError: anderer Fehlertyp'), 'TypeError protokolliert');

    T::same(
        ['admin_bulk', 'admin_update', 'role_deleted', 'role_renamed'],
        (static function (): array {
            $values = [];
            foreach ((new \ReflectionClass(UserService::class))->getConstants() as $name => $value) {
                if (str_starts_with($name, 'ROLE_SOURCE_')) {
                    $values[] = $value;
                }
            }
            sort($values);

            return $values;
        })(),
        'Quellen als Konstanten ROLE_SOURCE_* (ohne sso, das setzt EntraSsoService)'
    );
});

// ── Quelltext: Auslösestellen, Reihenfolge, Doku, Schreibstellen ────────────────────────────────────────

/** Text der Methode `$name` (bis zur schließenden Klammer in Einrückung 4), leer wenn nicht gefunden. */
function urhMethod(string $source, string $name): string
{
    return preg_match('/function\s+' . preg_quote($name, '/') . '\s*\(.*?\n    \}\n/s', $source, $match) === 1 ? $match[0] : '';
}

function urhBefore(string $text, string $first, string $second): bool
{
    $a = strpos($text, $first);
    $b = strpos($text, $second);

    return $a !== false && $b !== false && $a < $b;
}

/**
 * Prüft die Auslösestellen im Quelltext; liefert die Namen verletzter Regeln.
 *
 * @return list<string>
 */
function urhSiteProblems(string $users, string $roles, string $entra): array
{
    $problems = [];
    $announce = urhMethod($users, 'announceRoleChange');
    if (substr_count($users, "Hooks::doAction('user_role_changed'") !== 1
        || !str_contains($announce, "Hooks::doAction('user_role_changed', \$userId, \$fromRole, \$toRole, \$source)")) {
        $problems[] = 'announceRoleChange löst den Hook als einzige Stelle in UserService aus';
    }
    if (!str_contains($announce, 'strcasecmp(')) {
        $problems[] = 'announceRoleChange vergleicht ohne Groß-/Kleinschreibung';
    }
    if (!str_contains($announce, 'catch (\\Throwable') || !str_contains($announce, "withChannel('user.service')")) {
        $problems[] = 'announceRoleChange schützt vor Ausnahmen von Verbrauchern und protokolliert (Kanal user.service)';
    }

    $update = urhMethod($users, 'updateUser');
    if (!urhBefore($update, "\$this->db->update('users'", 'self::ROLE_SOURCE_ADMIN_UPDATE')
        || !urhBefore($update, "logAction('user_updated'", 'self::ROLE_SOURCE_ADMIN_UPDATE')
        || !str_contains($update, 'self::announceRoleChange(')) {
        $problems[] = 'updateUser meldet admin_update nach dem Schreiben';
    }
    if (!str_contains(urhMethod($users, 'bulkAction'), "\$this->changeRole(\$user_id, (string)\$data['role'], self::ROLE_SOURCE_ADMIN_BULK)")) {
        $problems[] = 'bulkAction ruft changeRole mit admin_bulk';
    }
    $change = urhMethod($users, 'changeRole');
    if (!urhBefore($change, "->update('users'", 'self::announceRoleChange(') || !str_contains($change, '$from === null')) {
        $problems[] = 'changeRole meldet nach dem Schreiben und lehnt unbekannte Konten ab';
    }

    foreach ([['updateRole', 'ROLE_SOURCE_ROLE_RENAMED'], ['deleteRole', 'ROLE_SOURCE_ROLE_DELETED']] as [$method, $constant]) {
        $body = urhMethod($roles, $method);
        if (!urhBefore($body, 'lockUserIdsWithRole(', 'UPDATE {$this->prefix}')) {
            $problems[] = $method . ' liest die Konto-IDs vor dem ersten UPDATE';
        }
        if (!urhBefore($body, '->commit()', 'announceRoleChanges(') || !str_contains($body, 'UserService::' . $constant)) {
            $problems[] = $method . ' meldet ' . $constant . ' nach dem Commit';
        }
    }
    if (!str_contains(urhMethod($roles, 'lockUserIdsWithRole'), 'FOR UPDATE')) {
        $problems[] = 'lockUserIdsWithRole sperrt mit FOR UPDATE';
    }
    if (!str_contains($entra, "Hooks::doAction('user_role_changed', \$userId, \$from, \$to, 'sso')")) {
        $problems[] = 'EntraSsoService meldet sso';
    }

    return $problems;
}

/** Ersetzt genau einen Text; prüft, dass der Suchtext vorkommt (sonst wäre die Gegenprobe wirkungslos). */
function urhBad(string $source, string $search, string $replace): string
{
    T::same(1, substr_count($source, $search), 'Gegenprobe: Suchtext kommt genau einmal vor (' . substr($search, 0, 50) . '…)');

    return str_replace($search, $replace, $source);
}

/** Schreibt der Text die Rolle eines Kontos (SQL oder Database::update)? */
function urhWritesAccountRole(string $text): bool
{
    return preg_match('/UPDATE\s+\S*users\s+SET\s+role\b/i', $text) === 1
        || preg_match('/->update\(\s*[\'"]users[\'"]\s*,\s*\[\s*[\'"]role[\'"]/', $text) === 1;
}

/** @return list<string> Dateien unter CMS/, die die Rolle bestehender Konten schreiben (relativ zu CMS/). */
function urhRoleWriteFiles(): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator(ABSPATH, \FilesystemIterator::SKIP_DOTS),
        static fn (\SplFileInfo $entry): bool => !$entry->isDir() || !in_array($entry->getFilename(), ['assets', 'uploads', 'cache', 'logs', 'vendor', 'node_modules', '.git'], true)
    ));
    foreach ($iterator as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'php' && urhWritesAccountRole((string) file_get_contents($entry->getPathname()))) {
            $files[] = substr($entry->getPathname(), strlen(ABSPATH));
        }
    }
    sort($files);

    return $files;
}

T::run('Quelltext: Auslösestellen und Reihenfolge (mit Gegenprobe auf schlechten Beispieltexten)', static function (): void {
    $users = (string) file_get_contents(ABSPATH . 'core/Services/UserService.php');
    $roles = (string) file_get_contents(ABSPATH . 'admin/modules/users/RolesModule.php');
    $entra = (string) file_get_contents(ABSPATH . 'core/Auth/SSO/EntraSsoService.php');

    T::same([], urhSiteProblems($users, $roles, $entra), 'alle Auslösestellen vorhanden, richtig geordnet und mit Ausnahmeschutz');

    $bad = [
        'Aufruf in updateUser entfernt' => [urhBad($users, "self::announceRoleChange(\$user_id, (string) (\$user->role ?? ''), (string) \$update_data['role'], self::ROLE_SOURCE_ADMIN_UPDATE);", ''), $roles, $entra, 'updateUser meldet admin_update nach dem Schreiben'],
        'kein Vergleich in announceRoleChange' => [urhBad($users, 'strcasecmp($fromRole, $toRole)', 'strcmp($fromRole, $toRole)'), $roles, $entra, 'announceRoleChange vergleicht ohne Groß-/Kleinschreibung'],
        'kein Ausnahmeschutz' => [urhBad($users, 'catch (\\Throwable $e) {' . "\n            Logger::instance()->withChannel('user.service')->warning('Ein Verbraucher", 'catch (\\LogicException $e) {' . "\n            Logger::instance()->withChannel('user.service')->warning('Ein Verbraucher"), $roles, $entra, 'announceRoleChange schützt vor Ausnahmen von Verbrauchern und protokolliert (Kanal user.service)'],
        'Sammelaktion ohne changeRole' => [urhBad($users, '$this->changeRole($user_id, (string)$data[\'role\'], self::ROLE_SOURCE_ADMIN_BULK)', '$this->db->update(\'users\', [\'role\' => $data[\'role\']], [\'id\' => $user_id])'), $roles, $entra, 'bulkAction ruft changeRole mit admin_bulk'],
        'changeRole ohne Prüfung auf unbekanntes Konto' => [urhBad($users, '$from === null || ', ''), $roles, $entra, 'changeRole meldet nach dem Schreiben und lehnt unbekannte Konten ab'],
        'Meldung beim Umbenennen entfernt' => [$users, urhBad($roles, '$this->announceRoleChanges($affectedUserIds, $currentRole, $newRole, UserService::ROLE_SOURCE_ROLE_RENAMED);', ''), $entra, 'updateRole meldet ROLE_SOURCE_ROLE_RENAMED nach dem Commit'],
        'Meldung beim Löschen entfernt' => [$users, urhBad($roles, '$this->announceRoleChanges($affectedUserIds, $role, $fallbackRole, UserService::ROLE_SOURCE_ROLE_DELETED);', ''), $entra, 'deleteRole meldet ROLE_SOURCE_ROLE_DELETED nach dem Commit'],
        'Konto-IDs ohne FOR UPDATE' => [$users, urhBad($roles, ' ORDER BY id ASC FOR UPDATE', ' ORDER BY id ASC'), $entra, 'lockUserIdsWithRole sperrt mit FOR UPDATE'],
        'SSO-Aufruf entfernt' => [$users, $roles, urhBad($entra, "'user_role_changed'", "'user_role_modified'"), 'EntraSsoService meldet sso'],
    ];
    foreach ($bad as $label => [$badUsers, $badRoles, $badEntra, $expected]) {
        $problems = urhSiteProblems($badUsers, $badRoles, $badEntra);
        T::check(in_array($expected, $problems, true), 'Gegenprobe „' . $label . '“ wird erkannt', 'gemeldet: ' . implode(' | ', $problems));
    }

    // Schlechtes Beispiel für die Reihenfolge: Meldung vor dem Commit, Konto-IDs erst nach dem UPDATE gelesen.
    $badOrder = <<<'PHP'
    public function updateRole(array $post): array
    {
        $this->db->execute("UPDATE {$this->prefix}users SET role = ? WHERE role = ?", [$new, $old]);
        $affectedUserIds = $this->lockUserIdsWithRole($old);
        $this->announceRoleChanges($affectedUserIds, $old, $new, UserService::ROLE_SOURCE_ROLE_RENAMED);
        $pdo->commit();
    }

    public function deleteRole(array $post): array
    {
        $this->db->execute("UPDATE {$this->prefix}users SET role = ? WHERE role = ?", [$fallback, $role]);
        $this->announceRoleChanges($affectedUserIds, $role, $fallback, UserService::ROLE_SOURCE_ROLE_DELETED);
        $pdo->commit();
    }

PHP;
    $problems = urhSiteProblems($users, $badOrder, $entra);
    T::same(
        [true, true, true, true],
        [
            in_array('updateRole liest die Konto-IDs vor dem ersten UPDATE', $problems, true),
            in_array('updateRole meldet ROLE_SOURCE_ROLE_RENAMED nach dem Commit', $problems, true),
            in_array('deleteRole liest die Konto-IDs vor dem ersten UPDATE', $problems, true),
            in_array('deleteRole meldet ROLE_SOURCE_ROLE_DELETED nach dem Commit', $problems, true),
        ],
        'Gegenprobe: falsche Reihenfolge (IDs nach dem UPDATE, Meldung vor dem Commit) wird erkannt'
    );
    T::same([], urhSiteProblems($users, $roles, $entra), 'Gegenprobe verändert die echten Quellen nicht');
});

T::run('Quelltext: Schreibstellen auf users.role melden den Wechsel', static function (): void {
    // Wächter gegen neue Schreibstellen ohne Hook: Jede Datei, die die Rolle bestehender Konten ändert, ist hier bekannt.
    T::same(
        ['admin/modules/users/RolesModule.php', 'core/Auth/SSO/DatabaseIdentityStore.php', 'core/Services/UserService.php'],
        urhRoleWriteFiles(),
        'bekannte Schreibstellen (RolesModule und UserService lösen user_role_changed aus, DatabaseIdentityStore über EntraSsoService mit Quelle sso)'
    );
    T::check(urhWritesAccountRole("\$db->execute('UPDATE cms_users SET role = ? WHERE id = ?', [\$r, \$id]);"), 'Gegenprobe: SQL-Schreibstelle wird erkannt');
    T::check(urhWritesAccountRole("\$this->db->update('users', ['role' => \$role], ['id' => \$id]);"), 'Gegenprobe: Database::update-Schreibstelle wird erkannt');
    T::check(!urhWritesAccountRole("\$db->execute('UPDATE cms_users SET status = ? WHERE role = ?', [\$s, \$r]);") && !urhWritesAccountRole("INSERT INTO cms_users (role) VALUES ('member')"), 'Gegenprobe: Status ändern und Konto anlegen sind kein Rollenwechsel');
});

T::run('Dokumentation: Quellen in HOOKS-REFERENCE.md', static function (): void {
    $doc = (string) file_get_contents(dirname(__DIR__, 2) . '/DOC/core/HOOKS-REFERENCE.md');
    $row = preg_match('/^\| `user_role_changed` \|.*$/m', $doc, $match) === 1 ? $match[0] : '';
    T::check($row !== '', 'Tabellenzeile für user_role_changed vorhanden');

    $missing = static function (string $text): array {
        $absent = [];
        foreach (['sso', 'admin_update', 'admin_bulk', 'role_renamed', 'role_deleted'] as $source) {
            if (!str_contains($text, "`'" . $source . "'`")) {
                $absent[] = $source;
            }
        }
        foreach (['$userId, $fromRole, $toRole, $source', 'UserService::updateUser()', 'UserService::bulkAction()', 'RolesModule', 'nie bei unveränderter Rolle'] as $phrase) {
            if (!str_contains($text, $phrase)) {
                $absent[] = $phrase;
            }
        }

        return $absent;
    };
    T::same([], $missing($row), 'Zeile nennt Signatur und alle fünf Quellen');
    T::same(['role_deleted'], $missing(str_replace("`'role_deleted'`", 'role_deleted', $row)), 'Gegenprobe: fehlende Quelle in der Doku wird erkannt');

    $constants = (new \ReflectionClass(UserService::class))->getConstants();
    $documented = true;
    foreach ($constants as $name => $value) {
        if (str_starts_with($name, 'ROLE_SOURCE_') && !str_contains($row, "`'" . $value . "'`")) {
            $documented = false;
        }
    }
    T::check($documented, 'jede Konstante ROLE_SOURCE_* steht in der Doku');
});

// ── Verhalten ausführen ─────────────────────────────────────────────────────────────────────────────────────────

urhBehaviour('sqlite', 'SQLite-Attrappe');

if (urhMariaConfigured()) {
    urhBehaviour('mariadb', 'MariaDB');

    T::run('[MariaDB] FOR UPDATE hält die Konten bis zum Commit fest (Umbenennen und Löschen)', static function (): void {
        foreach ([
            ['updateRole', 'Umbenennen', '/UPDATE\s+\S*role_permissions\s+SET/'],
            ['deleteRole', 'Löschen', '/UPDATE\s+\S*users\s+SET\s+role/'],
        ] as [$method, $label, $firstWrite]) {
            $f = new UrhFixture('mariadb', [1 => 'custom', 2 => 'custom', 3 => 'member'], ['custom']);
            $other = urhMariaConnect($f->db->databaseName);
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $probe = ['seen' => false, 'blocked' => null, 'code' => null];
            // Zeitpunkt: nach dem Auswählen der Konten, vor dem ersten Schreiben des Moduls. Eine zweite Verbindung versucht
            // jetzt, ein Konto der Rolle zu ändern; mit FOR UPDATE läuft sie in die Sperrzeit (Fehler 1205).
            $f->db->beforeExecute = static function (string $sql) use ($other, &$probe, $firstWrite): void {
                if ($probe['seen'] || preg_match($firstWrite, $sql) !== 1) {
                    return;
                }
                $probe['seen'] = true;
                try {
                    $other->prepare('UPDATE cms_users SET role = ? WHERE id = ?')->execute(['eindringling', 1]);
                    $probe['blocked'] = false;
                } catch (\PDOException $e) {
                    $probe['code'] = (int) ($e->errorInfo[1] ?? 0);
                    $probe['blocked'] = $probe['code'] === 1205;
                }
            };
            $result = $method === 'updateRole'
                ? $f->roles->updateRole(['current_role' => 'custom', 'role_slug' => 'partner'])
                : $f->roles->deleteRole(['role_slug' => 'custom', 'fallback_role' => 'member']);
            T::same([true, true, true], [$result['success'] ?? null, $probe['seen'], $probe['blocked']], $label . ': Konten sind zwischen Auswahl und Schreiben gesperrt (Sperrzeit-Fehler 1205 bei zweiter Verbindung, ist ' . json_encode($probe['code']) . ')');
            T::same([1, 2], array_column($f->events(), 0), $label . ': beide Konten der Rolle melden');
            $other = null;
        }
    });
} else {
    T::run('MariaDB-Läufe', static function (): void {
        T::info('übersprungen: CMS_TEST_DB_HOST/CMS_TEST_DB_USER nicht gesetzt (oder pdo_mysql fehlt)');
    });
}

// ── Gegenprobe der Suite: Mutanten der Quelldateien ─────────────────────────────────────────────────────────────

if ((string) getenv('CMS_TEST_MUTANT') === '') {
    T::run('Gegenprobe: jede Mutante lässt die Suite scheitern', static function (): void {
        $mutants = urhMutants();
        $queue = array_keys($mutants);
        $running = [];
        $results = [];
        $outputs = [];
        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < 6) {
                $name = array_shift($queue);
                if (!empty($mutants[$name]['db']) && !urhMariaConfigured()) {
                    T::info('Mutante ' . $name . ' übersprungen: braucht CMS_TEST_DB_HOST/CMS_TEST_DB_USER');
                    continue;
                }
                $output = (string) tempnam(sys_get_temp_dir(), 'urh-mutant-');
                $environment = array_merge(getenv(), ['CMS_TEST_MUTANT' => $name]);
                if (empty($mutants[$name]['db'])) {
                    $environment['CMS_TEST_DB_HOST'] = '';
                }
                $process = proc_open([PHP_BINARY, __FILE__], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']], $pipes, null, $environment);
                $running[$name] = [$process, $output];
            }
            foreach ($running as $name => [$process, $output]) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    continue;
                }
                $results[$name] = $status['exitcode'];
                $outputs[$name] = (string) file_get_contents($output);
                proc_close($process);
                unlink($output);
                unset($running[$name]);
            }
            usleep(20000);
        }
        foreach ($results as $name => $exitCode) {
            $tail = trim(substr($outputs[$name], -300));
            T::check($exitCode === 1, 'Mutante „' . $name . '“ (' . $mutants[$name]['rule'] . ') wird erkannt', $exitCode === 0 ? 'Mutante überlebt: Suite bleibt grün' : 'Exit ' . $exitCode . ' statt 1: ' . $tail);
        }
    });
}

T::finish();
