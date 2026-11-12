<?php
/**
 * Inhalts-Export/-Import als ZIP (System → Import/Export).
 *
 * Archiv: `manifest.json` (Format, CMS- und Schema-Version, Quell-Website, Anzahl je Typ, Autoren, SHA-256 je Datei),
 * `data/<typ>.jsonl` (ein Datensatz je Zeile) und `files/…` (Mediendateien). Grundlage sind die `ExchangeProvider`
 * der Inhaltstypen; die Reihenfolge beim Import bestimmt `importPriority()` (Begriffe → Medien → Seiten/Beiträge →
 * Hub-Sites), damit spätere Typen Verweise umschreiben können.
 *
 * Export und Import laufen schrittweise als `BatchJobService`-Auftrag (fortsetzbar, gesperrt gegen parallele Läufe).
 * Arbeitsdateien liegen im privaten Speicher (`exchange/`). Hochgeladene Archive werden vor dem Entpacken geprüft:
 * Größe, Anzahl und Namen der Einträge (kein Zip-Slip, keine absoluten Pfade), entpackte Gesamtgröße, Manifest,
 * Schema-Version und Prüfsummen. Alte Arbeitsverzeichnisse entfernt der tägliche Cron.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Exchange;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Logger;
use CMS\MigrationManager;
use CMS\Services\BatchJobService;
use CMS\Services\ContentTypes\ContentTypeRegistry;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;
use CMS\Services\PrivateStorageService;
use CMS\Version;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentExchangeService
{
    public const FORMAT = '365cms-content-exchange';
    public const FORMAT_VERSION = 1;
    public const AREA = 'exchange';
    public const JOB_EXPORT = 'content_export';
    public const JOB_IMPORT = 'content_import';
    public const MAX_UPLOAD_BYTES = 268435456;      // 256 MB Archiv
    public const MAX_EXTRACT_BYTES = 2147483648;    // 2 GB entpackt
    public const MAX_ENTRIES = 50000;
    public const EXPORT_BATCH = 100;
    public const IMPORT_BATCH = 25;
    public const KEEP_HOURS = 48;

    private static ?self $instance = null;

    /** @var (callable(string): bool)|null Rechteprüfung (Standard: angemeldeter Benutzer); für Cron/CLI ersetzbar. */
    private $capabilityChecker = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** @param callable(string): bool $checker */
    public function setCapabilityChecker(callable $checker): void
    {
        $this->capabilityChecker = $checker;
    }

    /**
     * Export-Provider, die der Benutzer nutzen darf (Capability des Typs).
     *
     * @return array<string, ExchangeProvider>
     */
    public function providers(?callable $can = null): array
    {
        $can ??= $this->capabilityChecker ?? static fn (string $capability): bool => \CMS\Auth::instance()->hasCapability($capability);
        $providers = [];
        foreach (ContentTypeRegistry::getInstance()->exchangeProviders() as $type => $provider) {
            if ($can($provider->capability())) {
                $providers[$type] = $provider;
            }
        }

        return $providers;
    }

    // ── Export ──────────────────────────────────────────────────────────────

    /**
     * @param list<string> $types
     * @return array{job:array<string,mixed>,resumed:bool}
     */
    public function startExport(array $types, ExchangeFilter $filter, int $userId): array
    {
        $providers = array_intersect_key($this->providers(), array_flip($types));
        if ($providers === []) {
            throw new \InvalidArgumentException((string) __('exchange.error.no_types'));
        }
        $total = 0;
        $counts = [];
        foreach ($providers as $type => $provider) {
            $counts[$type] = $provider->countExport($filter);
            $total += $counts[$type];
        }
        $token = bin2hex(random_bytes(12));
        $this->workDir($token);

        return BatchJobService::getInstance()->start(self::JOB_EXPORT, '', max(1, $total), [
            'token' => $token,
            'types' => array_keys($providers),
            'index' => 0,
            'cursor' => 0,
            'counts' => array_fill_keys(array_keys($providers), 0),
            'expected' => $counts,
            'filter' => $filter->toArray(),
            'file' => '',
        ], $userId > 0 ? $userId : null);
    }

    /** @return array<string, mixed> */
    public function stepExport(int $jobId): array
    {
        return BatchJobService::getInstance()->step($jobId, function (array $job): array {
            $state = $job['state'];
            $types = (array) $state['types'];
            $providers = ContentTypeRegistry::getInstance()->exchangeProviders();
            $dir = $this->workDir((string) $state['token']);
            $filter = ExchangeFilter::fromArray((array) $state['filter']);
            $processed = 0;
            $errors = [];

            if ((int) $state['index'] < count($types)) {
                $type = (string) $types[(int) $state['index']];
                $provider = $providers[$type] ?? null;
                if ($provider === null) {
                    $state['index']++;
                    $state['cursor'] = 0;

                    return ['state' => $state, 'errors' => [sprintf((string) __('exchange.error.type_missing'), $type)]];
                }
                $batch = $provider->exportBatch($filter, (int) $state['cursor'], self::EXPORT_BATCH);
                $handle = fopen($dir . 'data' . DIRECTORY_SEPARATOR . $type . '.jsonl', 'ab');
                if ($handle === false) {
                    throw new \RuntimeException('Exportdatei nicht beschreibbar.');
                }
                foreach ($batch->records as $record) {
                    fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
                    $processed++;
                }
                fclose($handle);
                foreach ($batch->files as $archivePath => $absolute) {
                    $relative = self::cleanArchivePath((string) $archivePath);
                    if ($relative === '' || !str_starts_with($relative, 'files/') || !is_file((string) $absolute)) {
                        $errors[] = sprintf((string) __('exchange.error.file_skipped'), (string) $archivePath);
                        continue;
                    }
                    $target = $dir . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    if (!is_dir(dirname($target))) {
                        mkdir(dirname($target), 0700, true);
                    }
                    if (!copy((string) $absolute, $target)) {
                        $errors[] = sprintf((string) __('exchange.error.file_skipped'), $relative);
                    }
                }
                $state['counts'][$type] = (int) ($state['counts'][$type] ?? 0) + count($batch->records);
                $state['cursor'] = $batch->nextCursor;
                if ($batch->done) {
                    $state['index']++;
                    $state['cursor'] = 0;
                }

                return ['processed' => $processed, 'state' => $state, 'errors' => $errors, 'message' => sprintf((string) __('exchange.progress.export'), (string) $provider->label())];
            }

            // Abschluss: Manifest schreiben und ZIP bauen.
            $file = $this->buildArchive((string) $state['token'], $state, (int) ($job['created_by'] ?? 0));
            $state['file'] = $file;

            return ['state' => $state, 'done' => true, 'message' => (string) __('exchange.progress.export_done')];
        });
    }

    /** Pfad eines fertigen Exports (nur Dateiname aus dem Job-Zustand, realpath-geprüft). */
    public function exportFile(string $name): ?string
    {
        if (preg_match('/^export-[0-9]{8}-[0-9]{6}-[a-f0-9]{24}\.zip$/', $name) !== 1) {
            return null;
        }

        return PrivateStorageService::getInstance()->resolve(self::AREA, 'exports/' . $name);
    }

    // ── Import: Upload, Prüfung, Vorschau ───────────────────────────────────

    /**
     * Nimmt ein hochgeladenes Archiv an, prüft und entpackt es in ein Staging-Verzeichnis.
     *
     * @param array<string, mixed> $upload Eintrag aus $_FILES
     * @return array{success:bool,token?:string,error?:string}
     */
    public function stageUpload(array $upload): array
    {
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
            return ['success' => false, 'error' => (string) __('exchange.error.upload')];
        }
        if ((int) ($upload['size'] ?? 0) > self::MAX_UPLOAD_BYTES || filesize($tmp) > self::MAX_UPLOAD_BYTES) {
            return ['success' => false, 'error' => sprintf((string) __('exchange.error.too_large'), (int) (self::MAX_UPLOAD_BYTES / 1048576))];
        }
        if (strtolower((string) pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'zip') {
            return ['success' => false, 'error' => (string) __('exchange.error.not_zip')];
        }

        return $this->stageArchive($tmp);
    }

    /**
     * @return array{success:bool,token?:string,error?:string}
     */
    public function stageArchive(string $archive): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['success' => false, 'error' => (string) __('exchange.error.zip_missing')];
        }
        $zip = new \ZipArchive();
        if ($zip->open($archive, \ZipArchive::RDONLY) !== true) {
            return ['success' => false, 'error' => (string) __('exchange.error.not_zip')];
        }

        $token = bin2hex(random_bytes(12));
        $dir = $this->stagingDir($token);
        try {
            $error = $this->extractSafely($zip, $dir);
            $zip->close();
            if ($error !== null) {
                $this->removeDir($dir);

                return ['success' => false, 'error' => $error];
            }
            $manifest = $this->validateManifest($dir);
            if (is_string($manifest)) {
                $this->removeDir($dir);

                return ['success' => false, 'error' => $manifest];
            }
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('exchange')->error('Import-Archiv konnte nicht vorbereitet werden.', ['exception' => $e->getMessage()]);
            $this->removeDir($dir);

            return ['success' => false, 'error' => (string) __('exchange.error.not_zip')];
        }

        return ['success' => true, 'token' => $token];
    }

    /** @return array<string, mixed>|null */
    public function manifest(string $token): ?array
    {
        $dir = $this->stagingDir($token, false);
        if ($dir === null || !is_file($dir . 'manifest.json')) {
            return null;
        }
        $manifest = json_decode((string) file_get_contents($dir . 'manifest.json'), true);

        return is_array($manifest) ? $manifest : null;
    }

    /**
     * Vorschau ohne Schreibzugriff: Anzahl je Typ und Aktion, die ersten Konflikte und ungültigen Einträge.
     *
     * @return array<string, array{label:string,total:int,create:int,conflict:int,invalid:int,samples:list<array{action:string,label:string,message:string}>}>
     */
    public function preview(string $token): array
    {
        $dir = $this->stagingDir($token, false);
        $manifest = $this->manifest($token);
        if ($dir === null || $manifest === null) {
            return [];
        }
        $context = new ImportContext(ImportContext::STRATEGY_SKIP, 0, false, [], $dir, $manifest);
        $result = [];
        foreach ($this->importProviders($manifest) as $type => $provider) {
            $row = ['label' => $provider->label(), 'total' => 0, 'create' => 0, 'conflict' => 0, 'invalid' => 0, 'samples' => []];
            foreach ($this->readRecords($dir, $type, 0, PHP_INT_MAX) as $record) {
                $row['total']++;
                try {
                    $preview = $provider->preview($record, $context);
                } catch (\Throwable) {
                    $preview = new ImportPreview(ImportPreview::ACTION_INVALID, '#' . $row['total'], '', (string) __('exchange.error.invalid_record'));
                }
                $row[$preview->action]++;
                if ($preview->action !== ImportPreview::ACTION_CREATE && count($row['samples']) < 20) {
                    $row['samples'][] = ['action' => $preview->action, 'label' => $preview->label, 'message' => $preview->message];
                }
            }
            $result[$type] = $row;
        }

        return $result;
    }

    /**
     * @param array{strategy?:string,draft?:bool,author_map?:array<int,int>,types?:list<string>} $options
     * @return array{job:array<string,mixed>,resumed:bool}
     */
    public function startImport(string $token, array $options, int $userId): array
    {
        $dir = $this->stagingDir($token, false);
        $manifest = $this->manifest($token);
        if ($dir === null || $manifest === null) {
            throw new \InvalidArgumentException((string) __('exchange.error.staging_missing'));
        }
        $providers = $this->importProviders($manifest);
        if (!empty($options['types'])) {
            $providers = array_intersect_key($providers, array_flip((array) $options['types']));
        }
        if ($providers === []) {
            throw new \InvalidArgumentException((string) __('exchange.error.no_types'));
        }
        $total = 0;
        foreach (array_keys($providers) as $type) {
            $total += (int) ($manifest['types'][$type] ?? 0);
        }
        $context = new ImportContext(
            (string) ($options['strategy'] ?? ImportContext::STRATEGY_SKIP),
            $userId,
            !empty($options['draft']),
            (array) ($options['author_map'] ?? []),
            $dir,
            $manifest
        );

        return BatchJobService::getInstance()->start(self::JOB_IMPORT, $token, max(1, $total), [
            'token' => $token,
            'types' => array_keys($providers),
            'index' => 0,
            'offset' => 0,
            'context' => $context->toState(),
            'report' => [],
        ], $userId > 0 ? $userId : null);
    }

    /** @return array<string, mixed> */
    public function stepImport(int $jobId): array
    {
        return BatchJobService::getInstance()->step($jobId, function (array $job): array {
            $state = $job['state'];
            $types = (array) $state['types'];
            $dir = $this->stagingDir((string) $state['token'], false);
            if ($dir === null) {
                return ['done' => true, 'errors' => [(string) __('exchange.error.staging_missing')], 'state' => $state];
            }
            if ((int) $state['index'] >= count($types)) {
                return $this->finishImport($state, (int) ($job['created_by'] ?? 0));
            }

            $type = (string) $types[(int) $state['index']];
            $provider = ContentTypeRegistry::getInstance()->exchangeProvider($type);
            $context = ImportContext::fromState((array) $state['context']);
            $processed = 0;
            $failed = 0;
            $errors = [];
            $records = $provider !== null ? $this->readRecords($dir, $type, (int) $state['offset'], self::IMPORT_BATCH) : [];
            foreach ($records as $record) {
                $processed++;
                try {
                    $result = $provider->import($record, $context);
                } catch (\Throwable $e) {
                    Logger::instance()->withChannel('exchange')->warning('Import-Datensatz fehlgeschlagen.', ['type' => $type, 'exception' => $e->getMessage()]);
                    $result = new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.save'));
                }
                $state['report'][$type][$result->status] = (int) ($state['report'][$type][$result->status] ?? 0) + 1;
                if ($result->status === ImportResult::FAILED) {
                    $failed++;
                }
                if ($result->message !== '' && ($result->status === ImportResult::FAILED || $result->status === ImportResult::RENAMED)) {
                    $label = (string) ($record['title'] ?? $record['name'] ?? $record['table_name'] ?? $record['path'] ?? '#' . ($record['id'] ?? ''));
                    $errors[] = $provider->label() . ' – ' . mb_substr($label, 0, 80) . ': ' . $result->message;
                }
            }
            $state['context'] = $context->toState();
            if (count($records) < self::IMPORT_BATCH) {
                $state['index']++;
                $state['offset'] = 0;
            } else {
                $state['offset'] = (int) $state['offset'] + count($records);
            }

            return [
                'processed' => $processed,
                'failed' => $failed,
                'state' => $state,
                'errors' => $errors,
                'message' => $provider !== null ? sprintf((string) __('exchange.progress.import'), $provider->label()) : '',
            ];
        });
    }

    public function cancel(int $jobId): bool
    {
        return BatchJobService::getInstance()->cancel($jobId);
    }

    /** Entfernt Arbeits- und Staging-Verzeichnisse sowie Exporte, die älter als KEEP_HOURS sind. */
    public function cleanup(): int
    {
        $removed = 0;
        $limit = time() - self::KEEP_HOURS * 3600;
        $base = PrivateStorageService::getInstance()->path(self::AREA);
        foreach (['work', 'import'] as $folder) {
            foreach (glob($base . $folder . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (filemtime($dir) < $limit) {
                    $this->removeDir($dir . DIRECTORY_SEPARATOR);
                    $removed++;
                }
            }
        }
        foreach (glob($base . 'exports' . DIRECTORY_SEPARATOR . '*.zip') ?: [] as $file) {
            if (filemtime($file) < $limit && unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    // ── Intern ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $manifest
     * @return array<string, ExchangeProvider>
     */
    private function importProviders(array $manifest): array
    {
        $types = array_keys((array) ($manifest['types'] ?? []));
        $providers = [];
        foreach ($this->providers() as $type => $provider) {
            if (in_array($type, $types, true)) {
                $providers[$type] = $provider;
            }
        }
        uasort($providers, static fn (ExchangeProvider $a, ExchangeProvider $b): int => $a->importPriority() <=> $b->importPriority());

        return $providers;
    }

    /** @return list<array<string, mixed>> */
    private function readRecords(string $dir, string $type, int $offset, int $limit): array
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $type) !== 1) {
            return [];
        }
        $file = $dir . 'data' . DIRECTORY_SEPARATOR . $type . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $records = [];
        $handle = new \SplFileObject($file, 'rb');
        $handle->seek($offset);
        while (!$handle->eof() && count($records) < $limit) {
            $line = trim((string) $handle->current());
            $handle->next();
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function buildArchive(string $token, array $state, int $userId): string
    {
        $dir = $this->workDir($token);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($dir)));
                // Nur Nutzdaten; Schutzdateien des privaten Speichers (.htaccess, index.html) gehören nicht ins Archiv.
                if (str_starts_with($relative, 'data/') || str_starts_with($relative, 'files/')) {
                    $files[$relative] = (string) hash_file('sha256', $item->getPathname());
                }
            }
        }
        ksort($files);

        $manifest = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'cms_version' => Version::CURRENT,
            'schema_version' => MigrationManager::SCHEMA_VERSION,
            'site_url' => defined('SITE_URL') ? (string) SITE_URL : '',
            'upload_url' => defined('UPLOAD_URL') ? (string) UPLOAD_URL : '',
            'created_at' => date('c'),
            'types' => (array) $state['counts'],
            'filter' => (array) $state['filter'],
            'authors' => $this->authors(),
            'files' => $files,
        ];
        file_put_contents($dir . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $name = 'export-' . date('Ymd-His') . '-' . $token . '.zip';
        $target = PrivateStorageService::getInstance()->path(self::AREA . '/exports') . $name;
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Export-Archiv konnte nicht angelegt werden.');
        }
        $zip->addFile($dir . 'manifest.json', 'manifest.json');
        foreach (array_keys($files) as $relative) {
            $zip->addFile($dir . str_replace('/', DIRECTORY_SEPARATOR, $relative), $relative);
        }
        $zip->close();
        $this->removeDir($dir);

        try {
            AuditLogger::instance()->log(AuditLogger::CAT_CONTENT, 'content.exported', sprintf('Inhalte exportiert (%s).', $name), 'content_exchange', null, ['types' => $state['counts'], 'user_id' => $userId]);
        } catch (\Throwable) {
        }

        return $name;
    }

    /** @return list<array{id:int,name:string}> */
    private function authors(): array
    {
        $db = Database::instance();
        $p = $db->getPrefix();
        try {
            $rows = $db->execute(
                "SELECT DISTINCT u.id, u.username, u.display_name FROM {$p}users u
                 WHERE u.id IN (SELECT author_id FROM {$p}posts) OR u.id IN (SELECT author_id FROM {$p}pages)"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => trim((string) ($row['display_name'] ?? '')) !== '' ? (string) $row['display_name'] : (string) $row['username'],
        ], $rows);
    }

    private function extractSafely(\ZipArchive $zip, string $dir): ?string
    {
        if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRIES) {
            return (string) __('exchange.error.entries');
        }
        $total = 0;
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                return (string) __('exchange.error.not_zip');
            }
            $name = (string) $stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            $clean = self::cleanArchivePath($name);
            if ($clean === '' || $clean !== $name || !($clean === 'manifest.json' || str_starts_with($clean, 'data/') || str_starts_with($clean, 'files/'))) {
                return sprintf((string) __('exchange.error.entry_name'), mb_substr($name, 0, 120));
            }
            $total += (int) $stat['size'];
            if ($total > self::MAX_EXTRACT_BYTES) {
                return (string) __('exchange.error.too_large_extracted');
            }
            $entries[$i] = $clean;
        }

        $root = realpath($dir);
        if ($root === false) {
            return (string) __('exchange.error.staging_missing');
        }
        foreach ($entries as $index => $relative) {
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                return (string) __('exchange.error.staging_missing');
            }
            $realParent = realpath($parent);
            if ($realParent === false || !str_starts_with($realParent . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
                return sprintf((string) __('exchange.error.entry_name'), $relative);
            }
            $in = $zip->getStream($zip->getNameIndex($index));
            $out = fopen($target, 'wb');
            if ($in === false || $out === false) {
                return (string) __('exchange.error.not_zip');
            }
            $written = stream_copy_to_stream($in, $out, self::MAX_EXTRACT_BYTES);
            fclose($in);
            fclose($out);
            if ($written === false) {
                return (string) __('exchange.error.not_zip');
            }
        }

        return null;
    }

    /** @return array<string, mixed>|string Manifest oder Fehlertext */
    private function validateManifest(string $dir): array|string
    {
        if (!is_file($dir . 'manifest.json')) {
            return (string) __('exchange.error.manifest');
        }
        $manifest = json_decode((string) file_get_contents($dir . 'manifest.json'), true);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== self::FORMAT || (int) ($manifest['format_version'] ?? 0) !== self::FORMAT_VERSION || !is_array($manifest['types'] ?? null)) {
            return (string) __('exchange.error.manifest');
        }
        $source = (int) preg_replace('/\D/', '', (string) ($manifest['schema_version'] ?? ''));
        $current = (int) preg_replace('/\D/', '', MigrationManager::SCHEMA_VERSION);
        if ($source <= 0 || $source > $current) {
            return sprintf((string) __('exchange.error.schema'), (string) ($manifest['schema_version'] ?? '?'), MigrationManager::SCHEMA_VERSION);
        }
        $listed = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        foreach ($listed as $relative => $hash) {
            $relative = self::cleanArchivePath((string) $relative);
            $path = $relative !== '' ? $dir . str_replace('/', DIRECTORY_SEPARATOR, $relative) : '';
            if ($path === '' || !is_file($path) || !hash_equals((string) $hash, (string) hash_file('sha256', $path))) {
                return sprintf((string) __('exchange.error.checksum_file'), (string) $relative);
            }
        }
        // Jede Datei im Archiv muss im Manifest stehen (keine untergeschobenen Dateien).
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo && $item->isFile()) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($dir)));
                if (in_array($relative, ['manifest.json', '.htaccess', 'index.html'], true)) {
                    continue;
                }
                if (!isset($listed[$relative])) {
                    return sprintf((string) __('exchange.error.checksum_file'), $relative);
                }
            }
        }

        return $manifest;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function finishImport(array $state, int $userId): array
    {
        $dir = $this->stagingDir((string) $state['token'], false);
        if ($dir !== null) {
            $this->removeDir($dir);
        }
        $labels = [];
        foreach ($this->providers() as $provider) {
            $labels[$provider->type()] = $provider->label();
        }
        $summary = [];
        foreach ((array) $state['report'] as $type => $counts) {
            $parts = [];
            foreach ((array) $counts as $status => $count) {
                $parts[] = __('exchange.status.' . $status) . ' ' . (int) $count;
            }
            $summary[] = ($labels[$type] ?? (string) $type) . ': ' . implode(', ', $parts);
        }
        try {
            AuditLogger::instance()->log(AuditLogger::CAT_CONTENT, 'content.imported', 'Inhalte importiert.', 'content_exchange', null, ['report' => $state['report'], 'user_id' => $userId], 'warning');
        } catch (\Throwable) {
        }

        return ['done' => true, 'state' => $state, 'message' => mb_substr(implode(' · ', $summary), 0, 250)];
    }

    private function workDir(string $token): string
    {
        $dir = PrivateStorageService::getInstance()->path(self::AREA . '/work/' . $token);
        if (!is_dir($dir . 'data')) {
            mkdir($dir . 'data', 0700, true);
        }

        return $dir;
    }

    private function stagingDir(string $token, bool $create = true): ?string
    {
        if (preg_match('/^[a-f0-9]{24}$/', $token) !== 1) {
            return null;
        }
        if ($create) {
            return PrivateStorageService::getInstance()->path(self::AREA . '/import/' . $token);
        }
        $path = PrivateStorageService::getInstance()->root() . self::AREA . DIRECTORY_SEPARATOR . 'import' . DIRECTORY_SEPARATOR . $token . DIRECTORY_SEPARATOR;

        return is_dir($path) ? $path : null;
    }

    public static function cleanArchivePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/')) {
            return '';
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return mb_strlen($path) <= 500 ? $path : '';
    }

    private function removeDir(string $dir): void
    {
        $root = realpath(PrivateStorageService::getInstance()->path(self::AREA));
        $real = realpath($dir);
        if ($root === false || $real === false || !str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR) || $real === $root) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($real);
    }
}
