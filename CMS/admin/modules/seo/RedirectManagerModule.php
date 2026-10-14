<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\AuditLogger;
use CMS\Services\CsvExchange;
use CMS\Services\RedirectService;

class RedirectManagerModule
{
    private const CSV_IMPORT_MAX_BYTES = 2 * 1024 * 1024;

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
        CsvExchange::stream('weiterleitungen', RedirectService::CSV_REDIRECT_EXPORT_COLUMNS, $rows);
    }

    public function streamNotFoundCsvExport(): never
    {
        $rows = $this->service->getNotFoundExportRows();
        $this->logCsvExport('seo.not_found.export.csv', '404-Protokoll wurde als CSV exportiert.', count($rows));
        CsvExchange::stream('404-monitor', RedirectService::CSV_NOT_FOUND_EXPORT_COLUMNS, $rows);
    }

    public function importRedirectsCsv(mixed $file): array
    {
        try {
            $content = CsvExchange::readUploadedFile($file, self::CSV_IMPORT_MAX_BYTES);
        } catch (\InvalidArgumentException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(300);
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
}
