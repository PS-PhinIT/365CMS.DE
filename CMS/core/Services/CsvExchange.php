<?php
/**
 * CSV-Export und -Import für Admin-Massenbearbeitung (Weiterleitungen, SEO-Metadaten).
 *
 * Exporte: UTF-8 mit BOM, Semikolon getrennt (Excel-kompatibel), Schutz vor CSV-Formel-Injection.
 * Importe: Trennzeichen-Erkennung (; , Tab), Windows-1252-Fallback, Spaltenzuordnung über Aliase.
 *
 * @package CMS\Services
 */

declare(strict_types=1);

namespace CMS\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class CsvExchange
{
    public const DELIMITER = ';';

    /**
     * @param array<int, string> $columns
     * @param iterable<array<string, mixed>> $rows
     */
    public static function stream(string $baseName, array $columns, iterable $rows): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $baseName = trim((string)preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($baseName)), '-_');
        $baseName = $baseName !== '' ? $baseName : 'export';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $baseName . '-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new \RuntimeException('CSV-Ausgabe konnte nicht initialisiert werden.');
        }

        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $columns, self::DELIMITER, '"', '');

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = self::sanitizeCell($row[$column] ?? '');
            }
            fputcsv($output, $line, self::DELIMITER, '"', '');
        }

        fclose($output);
        exit;
    }

    /**
     * Verhindert, dass Tabellenkalkulationen Zellinhalte als Formel ausführen.
     */
    public static function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $text = str_replace(["\r\n", "\r"], "\n", (string)$value);

        if (preg_match('/^\s*([=\+\-@\t\r\n]|[＝＋－＠])/u', $text) === 1) {
            $text = "\t" . $text;
        }

        return $text;
    }

    /**
     * Prüft einen Upload aus $_FILES und liefert dessen Inhalt.
     *
     * @throws \InvalidArgumentException mit einer für Admins lesbaren Meldung
     */
    public static function readUploadedFile(mixed $file, int $maxBytes): string
    {
        $maxLabel = self::formatBytes($maxBytes);

        if (!is_array($file) || !isset($file['error'], $file['tmp_name'])) {
            throw new \InvalidArgumentException('Bitte eine CSV-Datei auswählen.');
        }

        $error = (int)$file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new \InvalidArgumentException('Bitte eine CSV-Datei auswählen.');
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new \InvalidArgumentException('Die CSV-Datei ist zu groß (maximal ' . $maxLabel . ').');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Der Upload der CSV-Datei ist fehlgeschlagen.');
        }

        $tmpName = (string)$file['tmp_name'];
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \InvalidArgumentException('Ungültiger Datei-Upload.');
        }

        $size = (int)($file['size'] ?? filesize($tmpName));
        if ($size <= 0) {
            throw new \InvalidArgumentException('Die CSV-Datei ist leer.');
        }
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException('Die CSV-Datei ist zu groß (maximal ' . $maxLabel . ').');
        }

        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw new \InvalidArgumentException('Bitte eine Datei im Format .csv hochladen.');
        }

        $content = file_get_contents($tmpName);
        if (!is_string($content)) {
            throw new \InvalidArgumentException('Die hochgeladene CSV-Datei konnte nicht gelesen werden.');
        }

        return $content;
    }

    /**
     * Liest eine CSV-Datei mit Kopfzeile.
     *
     * @param array<string, array<int, string>> $headerAliases Feldname => erlaubte Spaltennamen (klein, Leer-/Bindestriche als _)
     * @param array<int, string> $requiredFields Felder, deren Spalte vorhanden sein muss
     * @return array<int, array<string, string>> Datensätze nach Tabellenzeile (Kopfzeile = 1); nur vorhandene Spalten sind gesetzt
     * @throws \InvalidArgumentException
     */
    public static function parse(string $content, array $headerAliases, array $requiredFields, int $maxRows, string $expectedColumnsHint = ''): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        if (preg_match('//u', $content) !== 1) {
            $converted = function_exists('mb_convert_encoding')
                ? mb_convert_encoding($content, 'UTF-8', 'Windows-1252')
                : (function_exists('iconv') ? iconv('Windows-1252', 'UTF-8//IGNORE', $content) : false);
            if (!is_string($converted) || preg_match('//u', $converted) !== 1) {
                throw new \InvalidArgumentException('Die CSV-Datei konnte nicht gelesen werden. Bitte als UTF-8 speichern.');
            }
            $content = $converted;
        }

        if (trim($content) === '') {
            throw new \InvalidArgumentException('Die CSV-Datei ist leer.');
        }

        $firstLine = preg_split('/\r\n|\r|\n/', $content, 2)[0] ?? '';
        $delimiter = self::DELIMITER;
        $bestCount = 0;
        foreach ([';', ',', "\t"] as $candidate) {
            $count = substr_count($firstLine, $candidate);
            if ($count > $bestCount) {
                $bestCount = $count;
                $delimiter = $candidate;
            }
        }

        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new \InvalidArgumentException('Die CSV-Datei konnte nicht verarbeitet werden.');
        }

        try {
            fwrite($handle, $content);
            rewind($handle);

            $header = fgetcsv($handle, 0, $delimiter, '"', '');
            if (!is_array($header)) {
                throw new \InvalidArgumentException('Die CSV-Datei enthält keine Kopfzeile.');
            }

            $columnMap = [];
            foreach ($header as $index => $label) {
                $field = self::resolveHeader((string)$label, $headerAliases);
                if ($field !== null && !in_array($field, $columnMap, true)) {
                    $columnMap[$index] = $field;
                }
            }

            $missing = array_values(array_diff($requiredFields, $columnMap));
            if ($missing !== []) {
                throw new \InvalidArgumentException(
                    'Der CSV-Datei fehlt die Spalte ' . implode(' / ', $missing) . '.'
                    . ($expectedColumnsHint !== '' ? ' Erwartete Spalten: ' . $expectedColumnsHint : '')
                );
            }

            $records = [];
            $line = 1;
            while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if ($cells === [null] || implode('', array_map(static fn ($cell): string => trim((string)$cell), $cells)) === '') {
                    continue;
                }

                if (count($records) >= $maxRows) {
                    throw new \InvalidArgumentException(sprintf(
                        'Die CSV-Datei enthält mehr als %d Datensätze. Bitte in mehrere Dateien aufteilen.',
                        $maxRows
                    ));
                }

                $record = [];
                foreach ($columnMap as $index => $field) {
                    $record[$field] = self::stripFormulaGuard((string)($cells[$index] ?? ''));
                }
                $records[$line] = $record;
            }
        } finally {
            fclose($handle);
        }

        if ($records === []) {
            throw new \InvalidArgumentException('Die CSV-Datei enthält keine Datensätze.');
        }

        return $records;
    }

    public static function parseBoolean(string $value): ?bool
    {
        $value = strtolower(trim($value));

        return match (true) {
            in_array($value, ['1', 'ja', 'j', 'yes', 'y', 'true', 'wahr', 'aktiv', 'active', 'x', 'on'], true) => true,
            in_array($value, ['0', 'nein', 'n', 'no', 'false', 'falsch', 'inaktiv', 'inactive', 'off'], true) => false,
            default => null,
        };
    }

    /**
     * @param array<string, array<int, string>> $headerAliases
     */
    private static function resolveHeader(string $label, array $headerAliases): ?string
    {
        $label = trim(
            (string)preg_replace('/[\s\-]+/u', '_', strtolower(trim($label, " \t\n\r\0\x0B\"'"))),
            '_'
        );

        foreach ($headerAliases as $field => $aliases) {
            if ($label === $field || in_array($label, $aliases, true)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Entfernt den beim Export vorangestellten Tab vor formelartigen Werten wieder.
     */
    private static function stripFormulaGuard(string $value): string
    {
        return preg_match('/^\t\s*([=\+\-@]|[＝＋－＠])/u', $value) === 1 ? substr($value, 1) : $value;
    }

    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int)round($bytes / 1024)) . ' KB';
    }
}
