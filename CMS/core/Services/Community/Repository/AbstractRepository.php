<?php
/**
 * Gemeinsame Grundlage der Community-Repositories: Verbindung, Tabellenpräfix und kleine Hilfen für Abfragen.
 *
 * Regeln für alle Repositories (Entwurfsvertrag PR #2a, §1):
 * - Zugriff nur über `Database::execute()` (wirft bei Fehlern) und `getPrefix()`/`lastInsertId()`; Zeilen als
 *   `array<string, mixed>` über `FETCH_ASSOC`. Der Rückgabewert von `insert()`/`update()`/`delete()` der Datenbankklasse
 *   ist nicht verlässlich (Fehler werden verschluckt) und wird hier nie benutzt.
 * - Nur gebundene Parameter; Spalten- und Tabellennamen stammen aus Allowlisten (`private const` der Klassen).
 * - Keine Uhr: Zeitwerte sind UTC-Texte `Y-m-d H:i:s` und kommen als Parameter vom Aufrufer.
 * - Portables SQL (läuft gegen MariaDB und gegen das SQLite-Double der Tests). Der Anhang für Zeilensperren steht nur in
 *   `forUpdate()`, damit das Double den Text an einer Stelle entfernen kann.
 * - Zähler werden nur atomar geändert (`counter()`), nie durch Lesen, Rechnen und Schreiben.
 * - Zeilensperren (`forUpdate()`): Mitgliedersperre vor der ersten nicht sperrenden Leseanweisung der Transaktion, sonst sieht
 *   `can(fresh)` einen veralteten Schnappschuss (REPEATABLE READ legt ihn bei der ersten Abfrage ohne Sperre an). Die
 *   `lockForUpdate()`-Methoden der Repositories lesen selbst nie ohne Sperre; gesperrte Zeilen müssen vorher existieren
 *   (Statistikzeile, Mutex-Zeile der Forum-Slugs: Anlage im Autocommit VOR der Transaktion), sonst entsteht eine Lückensperre
 *   und mit ihr Deadlocks. Ausführlich in `Support\Transaction`.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Repository;

use CMS\Database;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractRepository
{
    protected Database $db;
    protected string $prefix;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
    }

    /** Verbindung dieses Repositories (Dienste teilen sie mit `Transaction::run()`). */
    public function database(): Database
    {
        return $this->db;
    }

    /** Tabellenname mit Präfix; `$name` stammt immer aus einer Konstante, nie aus einer Eingabe. */
    final protected function table(string $name): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Tabellenname.');
        }

        return $this->prefix . $name;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    final protected function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->db->execute($sql, $params)->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    final protected function fetchAll(string $sql, array $params = []): array
    {
        return array_values($this->db->execute($sql, $params)->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Erste Spalte der ersten Zeile; `null`, wenn es keine Zeile gibt.
     *
     * @param list<mixed> $params
     */
    final protected function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->db->execute($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Führt eine Schreibanweisung aus und liefert `rowCount()`. MariaDB zählt geänderte, nicht gefundene Zeilen:
     * „gefunden“ ist nur dort belegt, wo die Anweisung immer einen Wert ändert (z. B. `version = version + 1`).
     *
     * @param list<mixed> $params
     */
    final protected function run(string $sql, array $params = []): int
    {
        return $this->db->execute($sql, $params)->rowCount();
    }

    /** Anhang für Zeilensperren; nur hier im Code steht der Text, das SQLite-Double entfernt ihn. */
    final protected static function forUpdate(): string
    {
        return ' FOR UPDATE';
    }

    /**
     * Allowlist für Spalten: unbekannte Schlüssel sind ein Programmierfehler und werfen, statt still verworfen zu werden.
     *
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     * @return array<string, mixed>
     */
    final protected static function pick(array $data, array $allowed): array
    {
        $unknown = array_diff(array_map('strval', array_keys($data)), $allowed);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unbekannte Spalte(n): ' . implode(', ', $unknown));
        }

        return $data;
    }

    /**
     * `INSERT` mit Spalten aus der Allowlist; liefert die neue AUTO_INCREMENT-ID.
     *
     * @param array<string, mixed> $data
     * @param list<string> $allowed
     */
    final protected function insertRow(string $table, array $data, array $allowed): int
    {
        $data = self::pick($data, $allowed);
        if ($data === []) {
            throw new \InvalidArgumentException('Keine Spalten zum Einfügen.');
        }
        $this->run(
            'INSERT INTO ' . $this->table($table) . ' (' . implode(', ', array_keys($data)) . ') VALUES (' . self::placeholders(count($data)) . ')',
            array_values($data)
        );

        return $this->db->lastInsertId();
    }

    /** `?, ?, ?` für `$count` Platzhalter (mindestens 1). */
    final protected static function placeholders(int $count): string
    {
        if ($count < 1) {
            throw new \InvalidArgumentException('Mindestens ein Platzhalter nötig.');
        }

        return implode(', ', array_fill(0, $count, '?'));
    }

    /**
     * Positive, eindeutige Ganzzahlen in der Reihenfolge des ersten Auftretens.
     *
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    final protected static function ids(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
    }

    /**
     * Zuweisung `<Spalte> = …` für einen atomaren Zähler mit Untergrenze 0. Ohne diese Form scheitert jede Verminderung
     * einer UNSIGNED-Spalte unter 0 in MariaDB mit „value is out of range“. Parameter: `counterParams($delta)`.
     * `$column` kommt aus einer Konstante, nie aus einer Eingabe.
     */
    final protected static function counter(string $column): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $column) !== 1) {
            throw new \InvalidArgumentException('Ungültiger Spaltenname.');
        }

        return $column . ' = CASE WHEN ' . $column . ' + ? > ? THEN ' . $column . ' + ? - ? ELSE 0 END';
    }

    /**
     * Parameter zu `counter()`: `[$plus, $minus, $plus, $minus]` mit nicht negativen Beträgen.
     *
     * @return list<int>
     */
    final protected static function counterParams(int $delta): array
    {
        $plus = max($delta, 0);
        $minus = max(-$delta, 0);

        return [$plus, $minus, $plus, $minus];
    }

    /**
     * Verletzung eines UNIQUE-Index. SQLSTATE 23000 steht für jede Integritätsverletzung (auch `NOT NULL` 1048 und
     * Fremdschlüssel 1451/1452); ein Fachfehler `slug_taken` darf nur der Treibercode auslösen: MariaDB/MySQL 1062 (Duplicate
     * entry). SQLite meldet für jede Art von Constraint 19 und unterscheidet nur im Text („UNIQUE constraint failed“).
     * Alle übrigen Integritätsverletzungen sind Programmfehler und werden vom Aufrufer weitergeworfen.
     */
    final protected static function isDuplicateKey(\PDOException $e): bool
    {
        if ((string) $e->getCode() !== '23000') {
            return false;
        }
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $driverCode === 1062
            || ($driverCode === 19 && str_contains((string) ($e->errorInfo[2] ?? $e->getMessage()), 'UNIQUE constraint failed'));
    }

    /**
     * Passen die Werte nicht in ihre Spalten (laut Schema)? Spaltenlängen und -bereiche prüft ein Repository vor dem Schreiben:
     * die Datenbank würde sonst im strengen Modus eine `PDOException` werfen (1406 zu lang, 1264 außerhalb des Bereichs,
     * 1366 ungültiges UTF-8) oder ohne `STRICT` still kürzen und klemmen. Nur vorhandene Schlüssel werden geprüft.
     *
     * Grenzen je Spalte (`$limits`): `['chars', N, nullable]` (VARCHAR(N), Zeichen), `['bytes', N, nullable]` (TEXT, Bytes),
     * `['uint']` (INT UNSIGNED, ganze Zahl 0 bis 4294967295, nie `null`), `['flag']` (Schalter, nie `null`; jeder Wert wird später zu 0/1).
     *
     * @param array<string, mixed> $data
     * @param array<string, array{0: string, 1?: int, 2?: bool}> $limits
     */
    final protected static function exceeds(array $data, array $limits): bool
    {
        foreach ($limits as $column => $limit) {
            if (!array_key_exists($column, $data)) {
                continue;
            }
            $value = $data[$column];
            if ($limit[0] === 'flag') {
                if ($value === null || is_array($value) || is_object($value)) {
                    return true;
                }
                continue;
            }
            if ($limit[0] === 'uint') {
                $number = is_int($value) ? $value : (is_string($value) && preg_match('/^\d{1,10}$/', $value) === 1 ? (int) $value : -1);
                if ($number < 0 || $number > 4294967295) {
                    return true;
                }
                continue;
            }
            if ($value === null) {
                if (!($limit[2] ?? false)) {
                    return true;
                }
                continue;
            }
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                return true;
            }
            $length = $limit[0] === 'bytes' ? strlen($value) : mb_strlen($value, 'UTF-8');
            if ($length > (int) ($limit[1] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Slug-Form wie `Slugger`: Kleinbuchstaben, Ziffern, einzelne Bindestriche, höchstens 160 Zeichen. Die strenge Form
     * macht Eindeutigkeitsprüfungen unabhängig von der Collation der Datenbank (MariaDB vergleicht ohne Groß-/Kleinschreibung).
     */
    final protected static function validSlug(string $slug): bool
    {
        return strlen($slug) <= 160 && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1;
    }
}
