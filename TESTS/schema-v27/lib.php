<?php
/**
 * Gemeinsame Hilfen für TESTS/schema-v27 (ohne Datenbank), TESTS/schema-upgrade (gegen MariaDB/MySQL) und
 * TESTS/schema-v27/dump-golden.php: eine kanonische Form für Spalten, Primärschlüssel und Indizes, damit dieselbe Struktur
 * aus drei Quellen vergleichbar ist: dem CREATE-TABLE-Text in `SchemaManager::getSchemaQueries()`, `information_schema`
 * (MariaDB und MySQL liefern Typen und Defaults verschieden formatiert) und den Tabellen in HANDOFF-PR2-PR5.md.
 *
 * Kanonisch: Typ klein und ohne Anzeigebreite der Ganzzahltypen (`int(10) unsigned` = `INT UNSIGNED` = `int unsigned`),
 * Default als Zeichenkette ohne Anführungszeichen (`NULL` = null, `current_timestamp()` = `CURRENT_TIMESTAMP`).
 */

declare(strict_types=1);

/** Typ ohne Anzeigebreite der Ganzzahltypen, klein: `int(10) unsigned` => `int unsigned`, `VARCHAR(20)` => `varchar(20)`. */
function v27CanonType(string $type): string
{
    $type = strtolower(trim((string) preg_replace('/\s+/', ' ', $type)));

    return (string) preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1', $type);
}

/** Default aus `information_schema` oder dem DDL-Text: ohne Anführungszeichen, `NULL` => null, Zeitstempel vereinheitlicht. */
function v27CanonDefault(mixed $default): ?string
{
    if ($default === null) {
        return null;
    }
    $value = trim((string) $default);
    if (strtoupper($value) === 'NULL') {
        return null;
    }
    if (preg_match('/^current_timestamp(\(\d*\))?$/i', $value) === 1) {
        return 'CURRENT_TIMESTAMP';
    }
    if (preg_match("/^'(.*)'$/s", $value, $m) === 1) {
        return str_replace("''", "'", $m[1]);
    }

    return $value;
}

/**
 * Zerlegt den CREATE-TABLE-Text einer Tabelle in die kanonische Form.
 *
 * @return array{columns: list<array{0: string, 1: string, 2: string, 3: ?string, 4: bool}>, primary: list<string>, indexes: array<string, array{unique: bool, columns: list<string>}>, columnCollations: list<string>, unclassified: list<string>}
 *         Spalten als [Name, Typ, 'YES'|'NO' (NULL erlaubt), Default, AUTO_INCREMENT]; `unclassified` = Zeilen, die weder
 *         Spalte noch Primärschlüssel noch Index sind (z. B. CHECK, FULLTEXT, GENERATED, Backticks, Tabellenoptionen im Rumpf)
 */
function v27ParseCreate(string $sql): array
{
    $columns = [];
    $primary = [];
    $collations = [];
    $unclassified = [];
    foreach (explode("\n", $sql) as $line) {
        $line = rtrim(trim($line), ',');
        if ($line === '' || str_starts_with($line, 'CREATE TABLE IF NOT EXISTS ') || str_starts_with($line, ')')) {
            continue;
        }
        if (preg_match('/^PRIMARY KEY\s*\((.+)\)$/i', $line, $m) === 1) {
            $primary = array_map('trim', explode(',', $m[1]));
            continue;
        }
        if (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+[a-z0-9_]+\s*\(.+\)$/i', $line) === 1) {
            continue; // Indizes: weiter unten
        }
        if (preg_match('/^([a-z_][a-z0-9_]*)\s+((?:[A-Za-z]+)(?:\(\d+(?:,\d+)?\))?(?:\s+UNSIGNED)?)(.*)$/i', $line, $m) !== 1
            || in_array(strtoupper($m[1]), ['CREATE', 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'ENGINE', 'FULLTEXT', 'SPATIAL', 'CHECK', 'GENERATED'], true)) {
            $unclassified[] = $line;
            continue;
        }
        [$name, $type, $rest] = [$m[1], $m[2], (string) $m[3]];
        $rest = (string) preg_replace("/\\s+COMMENT\\s+'(?:[^']|'')*'/i", '', $rest);
        if (preg_match('/\bCOLLATE\s+(\w+)/i', $rest, $c) === 1) {
            $collations[] = $name . ':' . $c[1];
        }
        $inlinePrimary = preg_match('/\bPRIMARY KEY\b/i', $rest) === 1;
        if ($inlinePrimary) {
            $primary = [$name];
        }
        $nullable = !$inlinePrimary && preg_match('/\bNOT NULL\b/i', $rest) !== 1;
        $default = null;
        if (preg_match("/\\bDEFAULT\\s+('(?:[^']|'')*'|NULL|CURRENT_TIMESTAMP|-?[0-9.]+)/i", $rest, $d) === 1) {
            $default = v27CanonDefault($d[1]);
        }
        $columns[] = [$name, v27CanonType($type), $nullable ? 'YES' : 'NO', $default, preg_match('/\bAUTO_INCREMENT\b/i', $rest) === 1];
    }

    $indexes = [];
    preg_match_all('/^\s*((?:UNIQUE\s+)?(?:KEY|INDEX))\s+([a-z0-9_]+)\s*\((.+)\),?\s*$/mi', $sql, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $indexes[$match[2]] = ['unique' => stripos($match[1], 'UNIQUE') === 0, 'columns' => array_map('trim', explode(',', $match[3]))];
    }
    ksort($indexes);

    return ['columns' => $columns, 'primary' => $primary, 'indexes' => $indexes, 'columnCollations' => $collations, 'unclassified' => $unclassified];
}

/**
 * Liest die kanonische Form der Tabellen aus `information_schema` (MariaDB/MySQL).
 *
 * @param list<string> $tables Tabellennamen ohne Präfix
 *
 * @return array<string, array{columns: list<array{0: string, 1: string, 2: string, 3: ?string, 4: bool}>, primary: list<string>, indexes: array<string, array{unique: bool, columns: list<string>}>}>
 */
function v27ReadStructure(PDO $pdo, string $database, string $prefix, array $tables): array
{
    $out = [];
    foreach ($tables as $table) {
        $full = $prefix . $table;
        $columns = [];
        $statement = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$database, $full]);
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$name, $type, $nullable, $default, $extra]) {
            $columns[] = [(string) $name, v27CanonType((string) $type), (string) $nullable, v27CanonDefault($default), stripos((string) $extra, 'auto_increment') !== false];
        }
        $primary = [];
        $indexes = [];
        $statement = $pdo->prepare('SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $statement->execute([$database, $full]);
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$index, $nonUnique, $column, $sub]) {
            $column = (string) $column . ($sub !== null && $sub !== '' ? '(' . $sub . ')' : '');
            if ($index === 'PRIMARY') {
                $primary[] = $column;
                continue;
            }
            $indexes[(string) $index]['unique'] = (int) $nonUnique === 0;
            $indexes[(string) $index]['columns'][] = $column;
        }
        ksort($indexes);
        $out[$table] = ['columns' => $columns, 'primary' => $primary, 'indexes' => $indexes];
    }

    return $out;
}

/**
 * Liest die Tabellen eines Abschnitts in HANDOFF-PR2-PR5.md („| `spalte` | typ | NOT NULL, Default x |“ und
 * „| `index` | Art | `a`, `b` | Zweck |“) in die kanonische Form.
 *
 * @return array{columns: list<array{0: string, 1: string, 2: string, 3: ?string, 4: bool}>, primary: list<string>, indexes: array<string, array{unique: bool, columns: list<string>}>}
 */
function v27ParseHandoffSection(string $section): array
{
    $columns = [];
    $primary = [];
    $indexes = [];
    foreach (explode("\n", $section) as $line) {
        $line = trim($line);
        if (preg_match('/^\| `([a-z_0-9]+)` \| ([a-z]+(?:\(\d+(?:,\d+)?\))?(?: unsigned)?) \| (NULL|NOT NULL, AUTO_INCREMENT|NOT NULL, Default (.+)) \|$/', $line, $m) === 1) {
            $nullable = $m[3] === 'NULL';
            $default = isset($m[4]) ? v27CanonDefault($m[4]) : null;
            $columns[] = [$m[1], v27CanonType($m[2]), $nullable ? 'YES' : 'NO', $default, $m[3] === 'NOT NULL, AUTO_INCREMENT'];
            continue;
        }
        if (preg_match('/^\| PRIMARY \| Primärschlüssel \| (.+?) \|/', $line, $m) === 1) {
            preg_match_all('/`([^`]+)`/', $m[1], $names);
            $primary = $names[1];
            continue;
        }
        if (preg_match('/^\| `((?:uq|idx)_[a-z0-9_]+)` \| (Index|eindeutig) \| (.+?) \|/', $line, $m) === 1) {
            preg_match_all('/`([^`]+)`/', $m[3], $names);
            $indexes[$m[1]] = ['unique' => $m[2] === 'eindeutig', 'columns' => $names[1]];
        }
    }
    ksort($indexes);

    return ['columns' => $columns, 'primary' => $primary, 'indexes' => $indexes];
}
