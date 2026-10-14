<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\RedirectService;

class RedirectManagerModule
{
    private const CSV_DELIMITER = ';';
    private const CSV_IMPORT_MAX_BYTES = 2 * 1024 * 1024;
    private const CSV_IMPORT_ALLOWED_EXTENSIONS = ['csv', 'txt'];

    private readonly RedirectService $service;

    public function __construct()
    {
        $this->service = RedirectService::getInstance();
    }

    public function getData(): array
    {
        return $this->service->getAdminData();
    }

    public function getRedirectManagerData(): array
    {
        return $this->service->getRedirectManagerData();
    }

    public function getNotFoundMonitorData(): array
    {
        return $this->service->getNotFoundMonitorData();
    }

    public function saveRedirect(array $post): array
    {
        return $this->service->saveRedirect($post);
    }

    public function deleteRedirect(int $id): array
    {
        return $this->service->deleteRedirect($id);
    }

    public function deleteRedirectsBySlug(string $slug): array
    {
        return $this->service->deleteRedirectsBySlug($slug);
    }

    public function toggleRedirect(int $id): array
    {
        return $this->service->toggleRedirect($id);
    }

    public function clearLogs(): array
    {
        return $this->service->clearLogs();
    }

    public function streamRedirectsCsvExport(): never
    {
        $rows = $this->service->getRedirectExportRows();
        $this->logCsvExport('seo.redirects.export.csv', 'Weiterleitungen wurden als CSV exportiert.', count($rows));
        $this->streamCsv('weiterleitungen', RedirectService::CSV_REDIRECT_EXPORT_COLUMNS, $rows);
    }

    public function streamNotFoundCsvExport(): never
    {
        $rows = $this->service->getNotFoundExportRows();
        $this->logCsvExport('seo.not_found.export.csv', '404-Protokoll wurde als CSV exportiert.', count($rows));
        $this->streamCsv('404-monitor', RedirectService::CSV_NOT_FOUND_EXPORT_COLUMNS, $rows);
    }

    public function importRedirectsCsv(mixed $file): array
    {
        $validationError = $this->validateCsvUpload($file);
        if ($validationError !== null) {
            return ['success' => false, 'error' => $validationError];
        }

        $content = file_get_contents((string)$file['tmp_name']);
        if (!is_string($content)) {
            return ['success' => false, 'error' => 'Die hochgeladene CSV-Datei konnte nicht gelesen werden.'];
        }

        $result = $this->service->importRedirectsFromCsv($content);

        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            'seo.redirects.import.csv',
            'Weiterleitungen wurden per CSV importiert.',
            'redirect',
            null,
            array_merge(['format' => 'csv'], is_array($result['summary'] ?? null) ? $result['summary'] : []),
            !empty($result['success']) ? 'info' : 'warning'
        );

        return $result;
    }

    private function validateCsvUpload(mixed $file): ?string
    {
        if (!is_array($file) || !isset($file['error'], $file['tmp_name'])) {
            return 'Bitte eine CSV-Datei auswählen.';
        }

        $error = (int)$file['error'];
        if ($error === UPLOAD_ERR_NO_FILE) {
            return 'Bitte eine CSV-Datei auswählen.';
        }
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return 'Die CSV-Datei ist zu groß (maximal 2 MB).';
        }
        if ($error !== UPLOAD_ERR_OK) {
            return 'Der Upload der CSV-Datei ist fehlgeschlagen.';
        }

        $tmpName = (string)$file['tmp_name'];
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return 'Ungültiger Datei-Upload.';
        }

        $size = (int)($file['size'] ?? filesize($tmpName));
        if ($size <= 0) {
            return 'Die CSV-Datei ist leer.';
        }
        if ($size > self::CSV_IMPORT_MAX_BYTES) {
            return 'Die CSV-Datei ist zu groß (maximal 2 MB).';
        }

        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($extension, self::CSV_IMPORT_ALLOWED_EXTENSIONS, true)) {
            return 'Bitte eine Datei im Format .csv hochladen.';
        }

        return null;
    }

    private function logCsvExport(string $action, string $description, int $rowCount): void
    {
        AuditLogger::instance()->log(
            AuditLogger::CAT_SETTING,
            $action,
            $description,
            'redirect',
            null,
            ['format' => 'csv', 'rows' => $rowCount],
            'info'
        );
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function streamCsv(string $baseName, array $columns, array $rows): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

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
        fputcsv($output, $columns, self::CSV_DELIMITER, '"', '');

        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $line[] = $this->sanitizeCsvCell($row[$column] ?? '');
            }
            fputcsv($output, $line, self::CSV_DELIMITER, '"', '');
        }

        fclose($output);
        exit;
    }

    private function sanitizeCsvCell(mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", (string)$value);

        if (preg_match('/^\s*([=\+\-@\t\r\n]|[＝＋－＠])/u', $text) === 1) {
            $text = "\t" . $text;
        }

        return $text;
    }
}
