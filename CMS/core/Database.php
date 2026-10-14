<?php
/**
 * Database Class - PDO Wrapper with Security
 * 
 * Secure database abstraction layer with prepared statements
 * 
 * @package CMSv2\Core
 */

declare(strict_types=1);

namespace CMS;

use PDO;
use PDOException;
use CMS\Contracts\DatabaseInterface;

if (!defined('ABSPATH')) {
    exit;
}

/** @implements DatabaseInterface */
class Database implements DatabaseInterface
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;
    public string $prefix = 'cms_';
    public string $last_error = '';
    private ?\PDOStatement $lastStatement = null;
    
    /**
     * Singleton instance
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Alias for instance() – WordPress-style naming used by plugins
     */
    public static function get_instance(): self
    {
        return self::instance();
    }
    
    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->connect();
        
        // H-10: Schema-Erstellung via SchemaManager (ausgelagert aus Database-God-Klasse)
        try {
            (new SchemaManager($this))->createTables();
        } catch (\Exception $e) {
            error_log('Database: SchemaManager::createTables() warning: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            // Nicht werfen – App soll weiterlaufen
        }
    }

    /**
     * Connect to database
     */
    private function connect(): void
    {
        // Präfix aus Konfigurationskonstante übernehmen (muss VOR SchemaManager gesetzt sein)
        $this->prefix = defined('DB_PREFIX') ? DB_PREFIX : 'cms_';

        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                // H-11: Native Prepared Statements (sicherer, kein PHP-seitiges Quoting)
                // bindParams() stellt sicher, dass LIMIT/OFFSET als PARAM_INT gebunden werden,
                // sodass MariaDB diese korrekt akzeptiert (keine String-Quotierung wie bei Emulation).
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Sitzungszeitzone an PHP angleichen: Viele Abfragen vergleichen NOW()/CURRENT_TIMESTAMP-
            // Werte mit date(...) aus PHP (Rate-Limits, Firewall, Sicherheitsalarme, Mail-Queue).
            // Läuft MySQL z. B. in UTC und PHP in Europe/Berlin, lag das Prüffenster sonst in der Zukunft
            // und Login-/Reset-/API-Limits zählten nie. Fehler hier dürfen die Verbindung nicht kippen.
            try {
                $offset = date('P');
                if (preg_match('/^[+-]\d{2}:\d{2}$/', $offset) === 1) {
                    $this->pdo->exec("SET time_zone = '" . $offset . "'");
                }
            } catch (\Throwable $e) {
                error_log('Database: time_zone konnte nicht gesetzt werden: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            }
            
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $this->sanitizeDiagnosticText($e->getMessage()));
            $this->pdo = null;
            throw new \RuntimeException('Database connection failed. Please check the database configuration.', 0, $e);
        }
    }
    
    /**
     * Create required tables (Public wrapper for repair)
     * H-10: Delegiert an MigrationManager
     */
    public function repairTables(): void
    {
        (new MigrationManager($this))->repairTables();
    }



    
    /**
     * Prepare statement
     */
    public function prepare(string $sql): \PDOStatement|false
    {
        if ($this->pdo === null) {
            error_log('Database::prepare() - CRITICAL: PDO connection is null!');
            error_log('SQL attempted: ' . $this->summarizeSqlForLog($sql));
            error_log('DB Config - Host: ' . $this->sanitizeDiagnosticText((string)DB_HOST, 160) . ', Name: ' . $this->sanitizeDiagnosticText((string)DB_NAME, 160));
            throw new \RuntimeException('Database connection is not available. PDO is null.');
        }
        
        $this->noteWrite($sql);

        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false) {
                error_log('Database::prepare() - prepare() returned false: ' . $this->summarizeSqlForLog($sql));
                $errorInfo = $this->pdo->errorInfo();
                error_log('PDO Error: ' . $this->formatPdoErrorInfo($errorInfo));
                throw new \RuntimeException('PDO prepare failed: ' . $this->sanitizeDiagnosticText((string)($errorInfo[2] ?? 'Unknown error')));
            }
            return $stmt;
        } catch (\PDOException $e) {
            error_log('Database::prepare() Exception: ' . $this->sanitizeDiagnosticText($e->getMessage()) . ' | SQL: ' . $this->summarizeSqlForLog($sql));
            throw new \RuntimeException('Database prepare error: ' . $this->sanitizeDiagnosticText($e->getMessage()), 0, $e);
        }
    }
    
    /**
     * Execute query (ungeparametrisiert – nur für vertrauenswürdige SQL-Strings!)
     */
    public function query(string $sql): \PDOStatement
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('Database connection is not available. PDO is null.');
        }

        $this->noteWrite($sql);

        $startedAt = microtime(true);
        $statement = $this->pdo->query($sql);
        $this->logQueryTelemetry($sql, null, $startedAt);

        return $statement;
    }

    /**
     * Leert den Options-Cache, sobald eine Anweisung in die Tabelle `settings` schreibt.
     */
    private function noteWrite(string $sql): void
    {
        $head = strtoupper(substr(ltrim($sql), 0, 7));
        if (!str_starts_with($head, 'INSERT') && !str_starts_with($head, 'UPDATE')
            && !str_starts_with($head, 'DELETE') && !str_starts_with($head, 'REPLACE')) {
            return;
        }

        if (preg_match('/`?' . preg_quote($this->prefix, '/') . 'settings`?(?![a-z0-9_])/i', $sql) === 1
            && class_exists(Services\OptionStore::class, false)) {
            Services\OptionStore::getInstance()->flush();
        }

        // RSS-Feed-Cache (Routing\ThemeRouter::serveRssFeed) bei Änderungen an Beiträgen/Kategorien verwerfen;
        // der Aufrufzähler (`views = views + 1`) zählt nicht als Inhaltsänderung.
        if (preg_match('/`?' . preg_quote($this->prefix, '/') . '(?:posts|post_categories)`?(?![a-z0-9_])/i', $sql) === 1
            && preg_match('/^\s*UPDATE\s+\S+\s+SET\s+views\s*=\s*views\s*\+\s*1\s+WHERE/i', $sql) !== 1) {
            self::invalidateFeedCache();
        }
    }

    /**
     * Löscht die zwischengespeicherten RSS-Feeds (cache/feed/*.xml).
     */
    public static function invalidateFeedCache(): void
    {
        if (!defined('ABSPATH')) {
            return;
        }

        foreach (glob(rtrim((string) ABSPATH, '/\\') . '/cache/feed/*.xml') ?: [] as $file) {
            @unlink($file);
        }
    }

    /**
     * Bind parameters to a prepared statement with correct PDO types.
     * Mit ATTR_EMULATE_PREPARES => false (native Prepared Statements) ist explizites
     * Type-Binding für LIMIT/OFFSET-Parameter erforderlich, damit PARAM_INT korrekt
     * an MySQL/MariaDB übergeben wird (kein String-Quoting durch PHP-Emulation).
     */
    private function bindParams(\PDOStatement $stmt, array $params): void
    {
        foreach (array_values($params) as $i => $value) {
            if (is_int($value)) {
                $stmt->bindValue($i + 1, $value, PDO::PARAM_INT);
            } elseif (is_bool($value)) {
                $stmt->bindValue($i + 1, $value, PDO::PARAM_BOOL);
            } elseif ($value === null) {
                $stmt->bindValue($i + 1, $value, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($i + 1, (string) $value, PDO::PARAM_STR);
            }
        }
    }

    private function logQueryTelemetry(string $sql, ?array $params, float $startedAt): void
    {
        if (!defined('CMS_DEBUG') || !CMS_DEBUG) {
            return;
        }

        Debug::query($sql, $params, microtime(true) - $startedAt);
    }

    /**
     * Execute query with parameters (prepared statement)
     */
    public function execute(string $sql, array $params = []): \PDOStatement
    {
        $startedAt = microtime(true);
        $stmt = $this->prepare($sql);
        $this->bindParams($stmt, $params);
        $stmt->execute();
        $this->logQueryTelemetry($sql, $params, $startedAt);
        return $stmt;
    }

    /**
     * Validiert einen Tabellen- oder Spaltennamen gegen SQL-Injection.
     *
     * @throws \InvalidArgumentException Bei ungültigem Bezeichner
     */
    private function validateIdentifier(string $name): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                'Ungültiger SQL-Bezeichner: ' . substr($name, 0, 50)
            );
        }
        return '`' . $name . '`';
    }

    /**
     * Insert data (mit Error-Tracking)
     */
    public function insert(string $table, array $data): int|bool
    {
        $startedAt = microtime(true);
        try {
            $table = $this->prefix . $table;
            $keys = array_keys($data);
            $escapedFields = array_map([$this, 'validateIdentifier'], $keys);
            $fields = implode(', ', $escapedFields);
            $placeholders = implode(', ', array_fill(0, count($keys), '?'));

            $sql = "INSERT INTO `{$table}` ({$fields}) VALUES ({$placeholders})";
            $this->lastStatement = $this->prepare($sql);
            $this->bindParams($this->lastStatement, array_values($data));
            $result = $this->lastStatement->execute();

            if (!$result) {
                $error = $this->lastStatement->errorInfo();
                $this->last_error = $error[2] ?? 'Unknown error';
                return false;
            }

            $this->logQueryTelemetry($sql, array_values($data), $startedAt);

            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Update data (mit Error-Tracking)
     */
    public function update(string $table, array $data, array $where): bool
    {
        $startedAt = microtime(true);
        try {
            $table = $this->prefix . $table;
            $set = [];
            $values = [];

            foreach ($data as $key => $value) {
                $set[] = $this->validateIdentifier($key) . " = ?";
                $values[] = $value;
            }

            $whereClauses = [];
            foreach ($where as $key => $value) {
                $whereClauses[] = $this->validateIdentifier($key) . " = ?";
                $values[] = $value;
            }

            $sql = "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE " . implode(' AND ', $whereClauses);
            $this->lastStatement = $this->prepare($sql);
            $this->bindParams($this->lastStatement, $values);
            $result = $this->lastStatement->execute();

            if (!$result) {
                $error = $this->lastStatement->errorInfo();
                $this->last_error = $error[2] ?? 'Unknown error';
                return false;
            }

            $this->logQueryTelemetry($sql, $values, $startedAt);

            return true;
        } catch (\PDOException $e) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Delete data
     */
    public function delete(string $table, array $where): bool
    {
        $startedAt = microtime(true);
        $table = $this->prefix . $table;
        $whereClauses = [];
        $values = [];

        foreach ($where as $key => $value) {
            $whereClauses[] = $this->validateIdentifier($key) . " = ?";
            $values[] = $value;
        }

        $sql  = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $whereClauses);
        $stmt = $this->prepare($sql);
        $this->bindParams($stmt, $values);
        $result = $stmt->execute();
        if ($result) {
            $this->logQueryTelemetry($sql, $values, $startedAt);
        }
        return $result;
    }

    /**
     * WordPress-compatible: Get single row as object
     */
    public function get_row(string $query, array $params = []): ?object
    {
        $stmt = $this->execute($query, $params);
        $result = $stmt->fetch(PDO::FETCH_OBJ);
        return $result ?: null;
    }

    /**
     * WordPress-compatible: Get single variable (column value)
     */
    public function get_var(string $query, array $params = []): mixed
    {
        $stmt = $this->execute($query, $params);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : null;
    }

    /**
     * WordPress-compatible: Get multiple rows as objects
     */
    public function get_results(string $query, array $params = []): array
    {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    }

    /**
     * WordPress-compatible: Get column values as array
     */
    public function get_col(string $query, array $params = []): array
    {
        $stmt = $this->execute($query, $params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get affected rows from last operation
     */
    public function affected_rows(): int
    {
        if ($this->lastStatement) {
            return $this->lastStatement->rowCount();
        }
        return 0;
    }
    
    /**
     * Get last insert ID
     */
    public function insert_id(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Kompatibilitätsalias für bestehende Module.
     */
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }
    
    /**
     * Get table prefix
     *
     * @deprecated Use getPrefix() instead
     * Accepts an optional table name: prefix('users') returns 'cms_users'.
     */
    public function prefix(string $table = ''): string
    {
        return $table !== '' ? $this->prefix . $table : $this->prefix;
    }

    /**
     * Get PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }
    
    /**
     * Get connection (alias for getPdo for compatibility)
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }
    
    /**
     * Get table prefix
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Prüft sicher, ob eine Tabelle in der aktuellen Datenbank existiert.
     *
     * Wichtig: MySQL/MariaDB akzeptiert native Prepared Statements nicht zuverlässig
     * für `SHOW TABLES/COLUMNS ... LIKE ?`. Deshalb laufen Schema-Kompatibilitätschecks
     * über `information_schema`.
     */
    public function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        return (int)($this->get_var(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?',
            [$table]
        ) ?? 0) > 0;
    }

    /**
     * Prüft sicher, ob eine Spalte in der aktuellen Datenbank existiert.
     */
    public function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }

        return (int)($this->get_var(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?',
            [$table, $column]
        ) ?? 0) > 0;
    }

    /**
     * Führt eine idempotente Schema-Prüfung (CREATE TABLE IF NOT EXISTS, fehlende
     * Spalten) nur einmal je Codestand aus statt bei jedem Request.
     *
     * Die Flag-Datei unter cache/ enthält einen Hash der Quelldatei; ändert ein
     * Update die Tabellendefinition, läuft die Prüfung einmal erneut. Wirft der
     * Callback eine Exception oder liefert er `false`, wird kein Flag geschrieben
     * und die Prüfung beim nächsten Request wiederholt.
     */
    public function runSchemaOnce(string $key, string $sourceFile, callable $callback): void
    {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key)) ?? '';
        if ($key === '' || !defined('ABSPATH')) {
            $callback();
            return;
        }

        $hash = is_file($sourceFile) ? @hash_file('crc32b', $sourceFile) : false;
        $revision = is_string($hash) && $hash !== '' ? $hash : 'unknown';
        $cacheDir = ABSPATH . 'cache';
        $flagFile = $cacheDir . '/db_runtime_' . $key . '_' . $this->prefix . $revision . '.flag';

        if (is_file($flagFile)) {
            return;
        }

        if ($callback() === false) {
            return;
        }

        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            return;
        }

        foreach (glob($cacheDir . '/db_runtime_' . $key . '_' . $this->prefix . '*.flag') ?: [] as $staleFlag) {
            if ($staleFlag !== $flagFile) {
                @unlink($staleFlag);
            }
        }

        @file_put_contents($flagFile, date('Y-m-d H:i:s'), LOCK_EX);
    }

    private function sanitizeDiagnosticText(string $value, int $maxLength = 500): string
    {
        $value = $this->maskInlineSecrets($value);
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');

        return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength, 'UTF-8') : substr($value, 0, $maxLength);
    }

    private function summarizeSqlForLog(string $sql): string
    {
        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;

        return $this->sanitizeDiagnosticText($sql, 800);
    }

    /** @param array<int|string,mixed> $errorInfo */
    private function formatPdoErrorInfo(array $errorInfo): string
    {
        $safe = [];
        foreach ($errorInfo as $key => $value) {
            $safe[$key] = is_string($value) ? $this->sanitizeDiagnosticText($value, 240) : $value;
        }

        $json = json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '[unserializable pdo error]';
    }

    private function maskInlineSecrets(string $value): string
    {
        $value = preg_replace('/([?&](?:token|csrf_token|nonce|key|secret|password|pass|db_pass)=)[^&\s]+/i', '$1***', $value) ?? $value;
        $value = preg_replace('/\b(Bearer\s+)[A-Za-z0-9._~+\/-]+=*/i', '$1***', $value) ?? $value;
        $value = preg_replace('/(password|passwd|secret|token|credential|api[_-]?key)(\s*[=:]\s*)\S+/i', '$1$2***', $value) ?? $value;

        return $value;
    }

    /**
     * Fetch all rows as an associative array
     */
    public static function fetchAll(string $query, array $params = []): array
    {
        $db = self::instance();
        $stmt = $db->execute($query, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch single row as an associative array
     */
    public static function fetchOne(string $query, array $params = []): ?array
    {
        $db = self::instance();
        $stmt = $db->execute($query, $params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Execute a query with parameters (Static wrapper)
     */
    public static function exec(string $query, array $params = []): bool
    {
        $db = self::instance();
        if (empty($params)) {
            return (bool) $db->query($query);
        }
        $stmt = $db->execute($query, $params);
        return $stmt->rowCount() >= 0;
    }
}
