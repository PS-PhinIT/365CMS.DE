<?php
/**
 * Backup Service
 * 
 * Datensicherung (Datenbank + Dateien) mit Manifest, SHA-256-Prüfsummen, Validierung und Restore.
 * Speicherort: CMS_BACKUP_PATH (config/app.php, empfohlen außerhalb des Webroots), sonst CMS/backups/.
 * Option „backup_encryption“: Teile werden mit CryptoService (XChaCha20-Poly1305) als *.enc gespeichert.
 * emailDatabaseBackup()/uploadToS3() existieren, sind aber an keine Oberfläche bzw. keinen Cron angebunden.
 * 
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services;

use CMS\Database;
use CMS\Http\Client as HttpClient;
use CMS\Json;
use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists(BackupService::class, false)) {

class BackupService
{
    private static ?self $instance = null;
    private Database $db;
    private HttpClient $httpClient;
    private ?string $backupRootPath = null;

    /** Option: neue Backups mit dem CryptoService-Schlüssel verschlüsseln ('1' = aktiv). */
    public const OPTION_ENCRYPTION = 'backup_encryption';
    /** Standard-Root ohne CMS_BACKUP_PATH (relativ zu ABSPATH, nur per .htaccess geschützt). */
    private const DEFAULT_BACKUP_DIRECTORY = 'backups';
    private const ENCRYPTED_FILE_SUFFIX = '.enc';
    private const ENCRYPTION_FORMAT = 'cmsenc2';
    /** Punkt-Präfix: collectBackupCandidates() listet Arbeitsverzeichnisse nicht als Backups. */
    private const DECRYPT_WORKSPACE_PREFIX = '.decrypt-';
    private const DECRYPT_WORKSPACE_MAX_AGE_SECONDS = 21600;
    private const MANIFEST_MAX_BYTES = 262144;
    private const DEFAULT_BACKUP_LIST_LIMIT = 25;
    private const MAX_BACKUP_LIST_LIMIT = 100;
    private const MAX_S3_REST_UPLOAD_BYTES = 26214400;
    private const BACKUP_NAME_PATTERN = '/^[a-z0-9][a-z0-9._-]{2,120}$/i';
    private const LEGACY_DATABASE_BACKUP_PATTERN = '/^database_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.sql(?:\.gz)?$/i';
    /** Von diesem Service angelegte Backup-Verzeichnisse (inkl. Zähler-Suffix bei Namenskollision). */
    private const OWN_BACKUP_DIRECTORY_PATTERN = '/^(?:full|database)_backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}(?:-[0-9]{1,2})?$/';
    private const MAX_BACKUP_NAME_ATTEMPTS = 50;
    /** Ein Backup-Verzeichnis ohne Manifest gilt erst nach dieser Zeit als abgebrochen statt „läuft noch“. */
    private const INCOMPLETE_BACKUP_GRACE_SECONDS = 3600;
    private const LEGACY_HTACCESS_CONTENT = 'deny from all';
    private const BACKUP_HTACCESS_CONTENT = "# Verweigert jeden direkten HTTP-Zugriff auf Backups.\n"
        . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n";
    private const S3_BUCKET_PATTERN = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/';
    private const S3_ENDPOINT_PATTERN = '/^[a-z0-9.-]+$/i';
    private const S3_REGION_PATTERN = '/^[a-z0-9-]{2,40}$/i';
    /**
     * Gesicherte Inhalte. assets/ gehört zum Core-Release (gebündelte PHP-/JS-Bibliotheken) und wird
     * bei jedem Update ersetzt – ein Restore darf keine veralteten Bibliotheken zurückspielen.
     */
    private const FILE_BACKUP_DIRECTORIES = ['uploads', 'themes', 'plugins'];
    /** Ältere Vollbackups enthalten noch assets/; diese Einträge werden beim Restore übersprungen. */
    private const RESTORE_SKIPPED_LEGACY_DIRECTORIES = ['assets'];
    private const ALLOWED_DOWNLOAD_PARTS = ['database', 'files'];
    private const BACKUP_HASH_ALGORITHM = 'sha256';
    private const VALIDATION_CRITICAL_TABLE_SUFFIXES = ['users', 'settings', 'pages', 'posts'];
    private const VALIDATION_MAX_CRITICAL_TABLES = 6;
    // Plausibilitätsgrenzen gegen manipulierte Archive; realistisch genug für gewachsene Medienbibliotheken.
    private const RESTORE_MAX_ARCHIVE_ENTRIES = 250000;
    private const RESTORE_MAX_ARCHIVE_FILE_BYTES = 4294967296;
    private const RESTORE_MAX_ARCHIVE_UNCOMPRESSED_BYTES = 34359738368;
    private const RESTORE_FREE_SPACE_FACTOR = 1.1;
    
    /**
     * Singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct()
    {
        $this->db = Database::instance();
        $this->httpClient = HttpClient::getInstance();
        $this->ensureBackupDir();
    }
    
    /**
     * Ensure backup directory exists
     */
    private function ensureBackupDir(): void
    {
        $backupRoot = $this->backupRoot();

        // Vor dem Anlegen prüfen, damit ein unzulässiger CMS_BACKUP_PATH kein Verzeichnis hinterlässt.
        $this->assertSafeBackupRoot($backupRoot);

        if (!is_dir($backupRoot) && !mkdir($backupRoot, 0750, true) && !is_dir($backupRoot)) {
            throw new \RuntimeException('Backup-Verzeichnis konnte nicht erstellt werden (CMS_BACKUP_PATH prüfen).');
        }

        $this->assertSafeBackupRoot($backupRoot);

        $htaccessPath = $backupRoot . '.htaccess';
        if (!is_file($htaccessPath)) {
            if (file_put_contents($htaccessPath, self::BACKUP_HTACCESS_CONTENT, LOCK_EX) === false) {
                throw new \RuntimeException('Backup-Schutzdatei konnte nicht geschrieben werden.');
            }
        } elseif (filesize($htaccessPath) <= 64) {
            // Alte Apache-2.2-Kurzform durch die 2.2/2.4-kompatible Variante ersetzen (fail-soft).
            $existing = file_get_contents($htaccessPath);
            if (is_string($existing) && strcasecmp(trim($existing), self::LEGACY_HTACCESS_CONTENT) === 0 && is_writable($htaccessPath)) {
                file_put_contents($htaccessPath, self::BACKUP_HTACCESS_CONTENT, LOCK_EX);
            }
        }

        $indexPath = $backupRoot . 'index.html';
        if (!is_file($indexPath) && file_put_contents($indexPath, '', LOCK_EX) === false) {
            throw new \RuntimeException('Backup-Indexdatei konnte nicht geschrieben werden.');
        }
    }
    
    /**
     * Create full backup (database + files)
     */
    public function createFullBackup(): array
    {
        $backupPath = null;

        try {
            $encryption = $this->resolveEncryptionForNewBackup();
            $timestamp = date('Y-m-d_H-i-s');
            ['name' => $backupName, 'path' => $backupPath] = $this->createBackupDirectory("full_backup_{$timestamp}");
            
            // 1. Database backup
            $dbFile = $this->createDatabaseBackup($backupPath);
            
            // 2. Files backup
            $filesZip = $this->createFilesBackup($backupPath);

            // 3. Optional verschlüsseln; Prüfsummen beziehen sich auf die gespeicherten (verschlüsselten) Dateien.
            if ($encryption !== null) {
                $dbFile = $this->encryptBackupPart($backupPath, $dbFile);
                $filesZip = $this->encryptBackupPart($backupPath, $filesZip);
            }
            
            // 4. Create manifest
            $manifest = [
                'timestamp' => time(),
                'date' => $timestamp,
                'type' => 'full',
                'database' => $dbFile,
                'files' => $filesZip,
                'size' => $this->getDirectorySize($backupPath),
                'cms_version' => $this->getCmsVersion(),
                'checksums' => $this->buildBackupChecksums($backupPath, $dbFile, $filesZip),
            ];
            if ($encryption !== null) {
                $manifest['encryption'] = $encryption;
            }
            
            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($manifestJson) || file_put_contents($backupPath . 'manifest.json', $manifestJson, LOCK_EX) === false) {
                throw new \RuntimeException('Manifest konnte nicht geschrieben werden.');
            }
            
            // Log backup
            $this->logBackup('full', $backupName, $manifest['size']);
            
            return [
                'success' => true,
                'path' => $backupPath,
                'name' => $backupName,
                'manifest' => $manifest,
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup')->error('Full backup could not be created.', [
                'exception' => $e,
            ]);
            $this->discardIncompleteBackup($backupPath);
            return [
                'success' => false,
                'error' => $this->buildServiceErrorMessage('Vollständiges Backup konnte nicht erstellt werden.', $e),
            ];
        }
    }
    
    /**
     * Create database backup only
     */
    public function createDatabaseBackup(?string $targetDir = null): string
    {
        $targetDir = $this->resolveTargetDirectory($targetDir);
        $timestamp = date('Y-m-d_H-i-s');
        $baseFilename = "database_{$timestamp}.sql";
        $useCompression = extension_loaded('zlib');
        $filename = $useCompression ? $baseFilename . '.gz' : $baseFilename;
        $filepath = $targetDir . $filename;
        
        try {
            $this->writeDatabaseBackupFile($filepath, $useCompression);

            $size = @filesize($filepath);
            if (!is_int($size) || $size < 1) {
                throw new \RuntimeException('Die erzeugte SQL-Backupdatei ist leer oder nicht lesbar.');
            }
            
            return basename($filepath);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup')->error('Database backup file could not be created.', [
                'target_dir' => $targetDir,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Create standalone database backup with manifest container
     */
    public function createStandaloneDatabaseBackup(): array
    {
        $backupPath = null;

        try {
            $encryption = $this->resolveEncryptionForNewBackup();
            $timestamp = date('Y-m-d_H-i-s');
            ['name' => $backupName, 'path' => $backupPath] = $this->createBackupDirectory("database_backup_{$timestamp}");
            $dbFile = $this->createDatabaseBackup($backupPath);
            if ($encryption !== null) {
                $dbFile = $this->encryptBackupPart($backupPath, $dbFile);
            }

            $manifest = [
                'timestamp' => time(),
                'date' => $timestamp,
                'type' => 'database',
                'database' => $dbFile,
                'files' => '',
                'size' => $this->getDirectorySize($backupPath),
                'cms_version' => $this->getCmsVersion(),
                'checksums' => $this->buildBackupChecksums($backupPath, $dbFile),
            ];
            if ($encryption !== null) {
                $manifest['encryption'] = $encryption;
            }

            $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($manifestJson) || file_put_contents($backupPath . 'manifest.json', $manifestJson, LOCK_EX) === false) {
                throw new \RuntimeException('Manifest konnte nicht geschrieben werden.');
            }

            $this->logBackup('database', $backupName, (int) $manifest['size']);

            return [
                'success' => true,
                'path' => $backupPath,
                'name' => $backupName,
                'manifest' => $manifest,
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup')->error('Standalone database backup could not be created.', [
                'exception' => $e,
            ]);
            $this->discardIncompleteBackup($backupPath);

            return [
                'success' => false,
                'error' => $this->buildServiceErrorMessage('Datenbank-Backup konnte nicht erstellt werden.', $e),
            ];
        }
    }
    

    private function writeDatabaseBackupFile(string $filepath, bool $compress): void
    {
        $handle = $compress ? gzopen($filepath, 'wb9') : fopen($filepath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Backup-Datei konnte nicht geöffnet werden.');
        }

        try {
            $this->writeDatabaseDumpToWriter(function (string $chunk) use ($handle, $compress): void {
                $written = $compress ? gzwrite($handle, $chunk) : fwrite($handle, $chunk);
                if ($written === false || $written < strlen($chunk)) {
                    throw new \RuntimeException('SQL-Backup konnte nicht vollständig geschrieben werden.');
                }
            });
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                $compress ? gzclose($handle) : fclose($handle);
            }

            if (is_file($filepath)) {
                @unlink($filepath);
            }

            throw $e;
        }

        $compress ? gzclose($handle) : fclose($handle);
    }

    /**
     * @param callable(string): void $writer
     */
    private function writeDatabaseDumpToWriter(callable $writer): void
    {
        $writer("-- CMS Database Backup\n");
        $writer("-- Generated: " . date('Y-m-d H:i:s') . "\n");
        $writer("-- Database: " . DB_NAME . "\n\n");
        $writer("SET FOREIGN_KEY_CHECKS=0;\n");
        $writer("SET SQL_MODE=\"NO_AUTO_VALUE_ON_ZERO\";\n\n");

        $stmt = $this->db->query('SHOW TABLES');
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            if (!is_string($table) || $table === '' || preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
                continue;
            }

            $quotedTable = $this->quoteSqlIdentifier($table);
            $createStmt = $this->db->query("SHOW CREATE TABLE {$quotedTable}");
            $create = $createStmt->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($create) || !isset($create['Create Table'])) {
                continue;
            }

            $writer("-- Table: {$table}\n");
            $writer("DROP TABLE IF EXISTS {$quotedTable};\n");
            $writer($create['Create Table'] . ";\n\n");

            $dataStmt = $this->db->query("SELECT * FROM {$quotedTable}");
            $wroteRows = false;
            // Generierte Spalten (z. B. posts.has_locale_*) berechnet die Datenbank selbst;
            // Werte dafür lehnt MySQL beim Restore ab.
            $generatedColumns = array_flip($this->getGeneratedColumns($table));

            while ($row = $dataStmt->fetch(\PDO::FETCH_ASSOC)) {
                if ($generatedColumns !== []) {
                    $row = array_diff_key($row, $generatedColumns);
                }
                $columns = implode(', ', array_map(fn (string $column): string => $this->quoteSqlIdentifier($column), array_keys($row)));
                $values = array_map(fn ($value) => $this->createSqlValueLiteral($value), array_values($row));
                $writer("INSERT INTO {$quotedTable} ({$columns}) VALUES (" . implode(', ', $values) . ");\n");
                $wroteRows = true;
            }

            if ($wroteRows) {
                $writer("\n");
            }
        }

        $writer("SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * @return list<string>
     */
    private function getGeneratedColumns(string $table): array
    {
        try {
            $rows = $this->db->get_results(
                "SELECT COLUMN_NAME AS column_name
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND (EXTRA LIKE '%GENERATED%' OR EXTRA LIKE '%PERSISTENT%' OR EXTRA LIKE '%VIRTUAL%')",
                [$table]
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(static fn (object $row): string => (string) $row->column_name, $rows));
    }

    private function createSqlValueLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $quoted = $this->db->getPdo()->quote((string) $value);
        if (!is_string($quoted)) {
            throw new \RuntimeException('SQL value could not be quoted for backup export.');
        }

        return $quoted;
    }

    private function quoteSqlIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
    
    /**
     * Create files backup (uploads, themes, plugins)
     */
    private function createFilesBackup(string $targetDir): string
    {
        $timestamp = date('Y-m-d_H-i-s');
        $filename = "files_{$timestamp}.zip";
        $filepath = $targetDir . $filename;
        
        if (!extension_loaded('zip')) {
            throw new \Exception('ZIP extension not loaded');
        }
        
        $zip = new \ZipArchive();
        
        if ($zip->open($filepath, \ZipArchive::CREATE) !== true) {
            throw new \Exception('Cannot create ZIP file');
        }
        
        // Add directories to backup
        foreach (self::FILE_BACKUP_DIRECTORIES as $dir) {
            $fullPath = ABSPATH . $dir;
            if (is_dir($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $dir);
            }
        }
        
        // Leere Archive legt libzip beim Schließen nicht an – Platzhalter-Verzeichnis sichert ein gültiges ZIP.
        if ($zip->numFiles === 0) {
            $zip->addEmptyDir(self::FILE_BACKUP_DIRECTORIES[0]);
        }

        // ZipArchive schreibt erst beim Schließen – ein Fehler hier (z. B. Speicherplatz) darf kein „erfolgreiches“ Backup ergeben.
        if (!$zip->close() || !is_file($filepath)) {
            throw new \RuntimeException('Datei-Archiv konnte nicht vollständig geschrieben werden.');
        }
        
        return basename($filepath);
    }
    
    /**
     * Add directory to ZIP recursively
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $path, string $basePath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $file) {
            if ($file->isLink() || !$file->isFile()) {
                continue;
            }

            // Relativen Pfad aus dem Iterator ableiten: realpath() würde bei symbolisch verlinkten
            // Basisverzeichnissen (z. B. uploads/ auf separatem Storage) falsche Archivpfade erzeugen.
            $relativePath = $basePath . '/' . str_replace('\\', '/', $files->getSubPathname());
            $zip->addFile($file->getPathname(), $relativePath);
        }
    }
    
    /**
     * Send database backup via email
     */
    public function emailDatabaseBackup(string $email): bool
    {
        try {
            $useCompression = extension_loaded('zlib');
            $tempFile = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'db_backup_' . time() . '.sql' . ($useCompression ? '.gz' : '');
            $attachmentName = $useCompression ? 'database_backup.sql.gz' : 'database_backup.sql';

            $this->writeDatabaseBackupFile($tempFile, $useCompression);

            $backupSize = is_file($tempFile) ? (int)filesize($tempFile) : 0;
            
            $subject = 'CMS Database Backup - ' . date('Y-m-d H:i');
            $message = "Automatisches Datenbank-Backup vom " . date('Y-m-d H:i:s') . "\n\n";
            $message .= "CMS Version: " . $this->getCmsVersion() . "\n";
            $message .= "Backup Größe: " . $this->formatBytes($backupSize);
            
            // Send email with attachment
            $sent = $this->sendEmailWithAttachment(
                $email,
                $subject,
                $message,
                $tempFile,
                $attachmentName
            );
            
            // Delete temp file
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
            
            if ($sent) {
                $this->logBackup('email', 'database_' . date('Y-m-d_H-i'), $backupSize);
            }
            
            return $sent;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('backup')->error('Database backup email could not be sent.', [
                'recipient' => $email,
                'exception' => $e,
            ]);
            return false;
        }
    }
    
    /**
     * Send email with attachment (delegiert an MailService)
     */
    private function sendEmailWithAttachment(
        string $to,
        string $subject,
        string $message,
        string $filePath,
        string $fileName
    ): bool {
        if (MailQueueService::getInstance()->shouldQueue()) {
            $result = MailService::getInstance()->queueWithAttachment(
                $to,
                $subject,
                $message,
                $filePath,
                $fileName,
                false,
                [
                    'X-365CMS-Test-Source' => 'backup-email',
                ],
                null,
                'backup-email'
            );

            return !empty($result['success']);
        }

        return MailService::getInstance()->sendWithAttachment(
            $to,
            $subject,
            $message,
            $filePath,
            $fileName,
            false, // Plain-Text-Nachricht
            [
                'X-365CMS-Test-Source' => 'backup-email',
            ]
        );
    }
    
    /**
     * Upload backup to S3 (AWS, DigitalOcean Spaces, etc.)
     */
    public function uploadToS3(string $backupPath, array $s3Config): bool
    {
        try {
            // Validate S3 config
            $required = ['endpoint', 'bucket', 'access_key', 'secret_key', 'region'];
            foreach ($required as $key) {
                if (!isset($s3Config[$key])) {
                    throw new \Exception("Missing S3 config: {$key}");
                }
            }
            
            // Upload über die signierte REST-API (SigV4). Ein SDK-Pfad war referenziert,
            // aber nie implementiert und führte bei installiertem AWS-SDK zu einem Fatal Error.
            return $this->uploadToS3WithREST($backupPath, $s3Config);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup')->error('Backup upload to S3 failed.', [
                'backup_path' => $backupPath,
                'exception' => $e,
            ]);
            return false;
        }
    }
    
    /**
     * Upload to S3 using REST API (without SDK)
     */
    private function uploadToS3WithREST(string $filePath, array $config): bool
    {
        if (!$this->isWithinBackupRoot($filePath) || !is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        if (!$this->isValidS3RestConfig($config)) {
            return false;
        }

        $bucket = strtolower(trim((string) $config['bucket']));
        $endpoint = strtolower(trim((string) $config['endpoint']));
        $region = strtolower(trim((string) ($config['region'] ?? 'us-east-1')));
        $accessKey = trim((string) $config['access_key']);
        $secretKey = (string) $config['secret_key'];

        $objectKey = basename($filePath);
        $encodedObjectKey = str_replace('%2F', '/', rawurlencode($objectKey));
        $host = $bucket . '.' . $endpoint;
        $url = 'https://' . $host . '/' . $encodedObjectKey;

        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize < 1 || $fileSize > self::MAX_S3_REST_UPLOAD_BYTES) {
            return false;
        }

        $fileContent = file_get_contents($filePath);
        if (!is_string($fileContent) || $fileContent === '') {
            return false;
        }

        $contentType = $this->getMimeType($filePath);
        $payloadHash = hash('sha256', $fileContent);
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $canonicalHeaders = 'content-type:' . $contentType . "\n"
            . 'host:' . $host . "\n"
            . 'x-amz-content-sha256:' . $payloadHash . "\n"
            . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
        $canonicalRequest = "PUT\n/{$encodedObjectKey}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $credentialScope = $dateStamp . '/' . $region . '/s3/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
        $signingKey = $this->getS3SignatureV4Key($secretKey, $dateStamp, $region);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;
        
        try {
            $result = $this->httpClient->put($url, $fileContent, [
                'timeout' => 120,
                'connectTimeout' => 10,
                'headers' => [
                    'Content-Type: ' . $contentType,
                    'Content-Length: ' . $fileSize,
                    'Host: ' . $host,
                    'X-Amz-Content-Sha256: ' . $payloadHash,
                    'X-Amz-Date: ' . $amzDate,
                    'Authorization: ' . $authorization,
                ],
                'userAgent' => '365CMS-BackupService/1.0',
                'maxBytes' => 1048576,
            ]);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup')->error('Backup upload via S3 REST failed.', [
                'backup_path' => $filePath,
                'bucket' => (string) ($config['bucket'] ?? ''),
                'endpoint' => (string) ($config['endpoint'] ?? ''),
                'exception' => $e,
            ]);
            $result = ['success' => false];
        }
        
        return !empty($result['success']);
    }
    
    /**
     * Get backup history
     */
    public function getBackupHistory(int $limit = 20): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->db->getPrefix()}settings
                WHERE option_name LIKE 'backup_log_%'
                ORDER BY option_name DESC
                LIMIT ?
            ");
            
            if (!$stmt) {
                return [];
            }
            
            $stmt->execute([$limit]);
            
            $history = [];
            while ($row = $stmt->fetch(\PDO::FETCH_OBJ)) {
                $data = Json::decode($row->option_value ?? null, true, null);
                if (is_array($data)) {
                    $history[] = $data;
                }
            }
            
            return $history;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('backup')->warning('Backup history could not be loaded.', [
                'limit' => $limit,
                'exception' => $e,
            ]);
            return [];
        }
    }

    private function getS3SignatureV4Key(string $secretKey, string $dateStamp, string $region): string
    {
        $dateKey = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
        $dateRegionKey = hash_hmac('sha256', $region, $dateKey, true);
        $dateRegionServiceKey = hash_hmac('sha256', 's3', $dateRegionKey, true);

        return hash_hmac('sha256', 'aws4_request', $dateRegionServiceKey, true);
    }
    
    /**
     * List available backups
     *
     * Liefert vollständige Backups (Manifest bzw. Legacy-SQL-Datei) sowie unvollständige, von diesem
     * Service angelegte Backup-Verzeichnisse (status = incomplete|pending), neueste zuerst.
     */
    public function listBackups(int $limit = self::DEFAULT_BACKUP_LIST_LIMIT): array
    {
        $limit = max(1, min(self::MAX_BACKUP_LIST_LIMIT, $limit));
        $backups = [];

        // Kandidaten (Verzeichnisse und Legacy-Dateien) gemeinsam nach Änderungszeit sortieren,
        // damit alte Einzel-Dumps neuere Verzeichnis-Backups nicht aus dem Limit verdrängen.
        foreach (array_keys($this->collectBackupCandidates()) as $item) {
            if (count($backups) >= $limit) {
                break;
            }

            $record = $this->readBackupRecord((string) $item);
            if ($record !== null) {
                $backups[] = $record;
            }
        }

        usort($backups, static fn (array $a, array $b): int => [(int) ($b['timestamp'] ?? 0), (string) ($b['name'] ?? '')]
            <=> [(int) ($a['timestamp'] ?? 0), (string) ($a['name'] ?? '')]);

        return $backups;
    }

    /**
     * Kompakter Backup-Status (z. B. für das Sicherheits-Audit): zählt nur vollständige Backups.
     *
     * @return array{count:int, incomplete:int, latest:array{name:string, type:string, timestamp:int}|null}
     */
    public function getBackupOverview(): array
    {
        $overview = ['count' => 0, 'incomplete' => 0, 'latest' => null];

        foreach (array_keys($this->collectBackupCandidates()) as $item) {
            $record = $this->readBackupRecord((string) $item);
            if ($record === null) {
                continue;
            }

            $status = (string) ($record['status'] ?? 'ok');
            if ($status === 'incomplete') {
                $overview['incomplete']++;
                continue;
            }

            if ($status !== 'ok') {
                continue;
            }

            $overview['count']++;
            $timestamp = (int) ($record['timestamp'] ?? 0);
            if ($overview['latest'] === null || $timestamp > $overview['latest']['timestamp']) {
                $overview['latest'] = [
                    'name' => (string) ($record['name'] ?? ''),
                    'type' => (string) ($record['type'] ?? 'database'),
                    'timestamp' => $timestamp,
                ];
            }
        }

        return $overview;
    }

    /**
     * @return array<string, int> Backup-Name => Änderungszeit, neueste zuerst
     */
    private function collectBackupCandidates(): array
    {
        $backupRoot = $this->backupRoot();
        if (!is_dir($backupRoot)) {
            return [];
        }

        $items = scandir($backupRoot);
        if ($items === false) {
            return [];
        }

        $candidates = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.htaccess' || $item === 'index.html') {
                continue;
            }

            $path = $backupRoot . $item;
            if ((is_dir($path) && $this->isAllowedBackupName($item))
                || (is_file($path) && $this->isLegacyDatabaseBackupFile($item))
            ) {
                $candidates[$item] = (int) (@filemtime($path) ?: 0);
            }
        }

        arsort($candidates, SORT_NUMERIC);

        return $candidates;
    }

    /**
     * Liest ein einzelnes Backup (Verzeichnis mit Manifest, unvollständiges eigenes Verzeichnis oder Legacy-Dump).
     *
     * @return array<string, mixed>|null
     */
    private function readBackupRecord(string $item): ?array
    {
        $item = trim(basename($item));
        if ($item === '' || $item === '.htaccess' || $item === 'index.html') {
            return null;
        }

        $path = $this->backupRoot() . $item;

        if ($this->isLegacyDatabaseBackupFile($item)) {
            if (!is_file($path) || is_link($path) || !$this->isWithinBackupRoot($path)) {
                return null;
            }

            $timestamp = (int) (@filemtime($path) ?: 0);

            return [
                'name' => $item,
                'path' => $path,
                'timestamp' => $timestamp,
                'date' => $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '-',
                'type' => 'database',
                'database' => $item,
                'files' => '',
                'size' => (int) (@filesize($path) ?: 0),
                'database_available' => true,
                'files_available' => false,
                'status' => 'ok',
                'error' => '',
            ];
        }

        if (!$this->isAllowedBackupName($item) || !is_dir($path) || is_link($path) || !$this->isWithinBackupRoot($path)) {
            return null;
        }

        $manifestFile = $path . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = $this->readBackupManifest($manifestFile);

        if ($manifest === null) {
            // Fremde Verzeichnisse (z. B. performance-rollbacks/) sind keine Backups dieses Services.
            if (preg_match(self::OWN_BACKUP_DIRECTORY_PATTERN, $item) !== 1) {
                return null;
            }

            $modifiedAt = (int) (@filemtime($path) ?: 0);
            $inProgress = $modifiedAt > 0 && (time() - $modifiedAt) < self::INCOMPLETE_BACKUP_GRACE_SECONDS;

            return [
                'name' => $item,
                'path' => $path,
                'timestamp' => $modifiedAt,
                'date' => $modifiedAt > 0 ? date('Y-m-d H:i:s', $modifiedAt) : '-',
                'type' => str_starts_with($item, 'full_backup_') ? 'full' : 'database',
                'database' => '',
                'files' => '',
                'size' => $this->getDirectorySize($path),
                'database_available' => false,
                'files_available' => false,
                'status' => $inProgress ? 'pending' : 'incomplete',
                'error' => $inProgress
                    ? 'Backup wird gerade erstellt oder wurde soeben abgebrochen (noch kein Manifest).'
                    : 'Backup ist unvollständig: manifest.json fehlt oder ist beschädigt (z. B. Abbruch durch Zeitlimit, Speicherplatz oder Fehler).',
            ];
        }

        $timestamp = (int) ($manifest['timestamp'] ?? 0);
        if ($timestamp <= 0) {
            $timestamp = (int) (@filemtime($manifestFile) ?: 0);
        }

        $issues = [];
        $databaseFile = trim(basename((string) ($manifest['database'] ?? '')));
        $filesArchive = trim(basename((string) ($manifest['files'] ?? '')));
        $databaseAvailable = $databaseFile !== '' && is_file($path . DIRECTORY_SEPARATOR . $databaseFile);
        $filesAvailable = $filesArchive !== '' && is_file($path . DIRECTORY_SEPARATOR . $filesArchive);
        if ($databaseFile === '' && $filesArchive === '') {
            $issues[] = 'Manifest verweist auf keine Sicherungsdateien';
        }
        if ($databaseFile !== '' && !$databaseAvailable) {
            $issues[] = 'Datenbank-Dump fehlt (' . $databaseFile . ')';
        }
        if ($filesArchive !== '' && !$filesAvailable) {
            $issues[] = 'Datei-Archiv fehlt (' . $filesArchive . ')';
        }

        $manifest['name'] = $item;
        $manifest['path'] = $path;
        $manifest['timestamp'] = $timestamp;
        $manifest['database_available'] = $databaseAvailable;
        $manifest['files_available'] = $filesAvailable;
        $manifest['status'] = $issues === [] ? 'ok' : 'incomplete';
        $manifest['error'] = $issues === [] ? '' : 'Backup ist unvollständig: ' . implode('; ', $issues) . '.';

        return $manifest;
    }

    /** @return array<string, mixed>|null */
    private function readBackupManifest(string $manifestFile): ?array
    {
        if (!is_file($manifestFile) || is_link($manifestFile)) {
            return null;
        }

        $manifestSize = @filesize($manifestFile);
        if ($manifestSize === false || $manifestSize < 2 || $manifestSize > self::MANIFEST_MAX_BYTES) {
            return null;
        }

        $manifestContents = @file_get_contents($manifestFile);
        if (!is_string($manifestContents) || $manifestContents === '') {
            return null;
        }

        $manifest = Json::decodeArray($manifestContents, []);

        return $manifest === [] ? null : $manifest;
    }

    /**
     * Liefert eine herunterladbare Backup-Datei (DB oder Dateien) für den Admin-Download.
     *
     * @return array{path:string,filename:string,content_type:string}|null
     */
    public function resolveDownloadableBackupFile(string $backupName, string $part = 'database'): ?array
    {
        $record = $this->findBackupRecord($backupName);
        if ($record === null) {
            return null;
        }

        $part = $this->normalizeDownloadPart($part);
        $path = $part === 'files'
            ? $this->resolveBackupFilesArchivePath($record)
            : $this->resolveBackupDatabasePath($record);

        if ($path === '' || !is_file($path) || !is_readable($path) || !$this->isWithinBackupRoot($path)) {
            return null;
        }

        return [
            'path' => $path,
            'filename' => basename($path),
            'content_type' => $this->getMimeType($path),
        ];
    }

    /**
     * Stellt ein Datenbank- oder Vollbackup wieder her.
     *
     * @return array{success:bool, restored_database:bool, restored_files:bool, rollback_backup:string}
     */
    public function restoreBackup(string $backupName): array
    {
        $record = $this->findBackupRecord($backupName);
        if ($record === null) {
            throw new \RuntimeException('Backup wurde nicht gefunden.');
        }

        if ((string) ($record['status'] ?? 'ok') !== 'ok') {
            throw new \RuntimeException((string) ($record['error'] ?? '') !== ''
                ? (string) $record['error'] . ' Wiederherstellung abgebrochen.'
                : 'Backup ist unvollständig; Wiederherstellung abgebrochen.');
        }

        $databasePath = $this->resolveBackupDatabasePath($record);
        $filesPath = $this->resolveBackupFilesArchivePath($record);

        if ($databasePath === '' && $filesPath === '') {
            throw new \RuntimeException('Backup enthält keine wiederherstellbaren Bestandteile.');
        }

        // Integrität und Archivstruktur vor dem (teuren) Rollback-Snapshot prüfen: beschädigte oder
        // veränderte Backups dürfen den Live-Bestand nicht überschreiben.
        $this->assertBackupPartIntegrity('Datenbank-Dump', $databasePath, (string) ($record['checksums']['database']['value'] ?? ''));
        $this->assertBackupPartIntegrity('Datei-Archiv', $filesPath, (string) ($record['checksums']['files']['value'] ?? ''));

        $workspace = null;
        $restoredDatabase = false;
        $restoredFiles = false;

        try {
            // Verschlüsselte Teile vorab vollständig entschlüsseln (authentifiziert); ein fehlender
            // Schlüssel oder eine manipulierte Datei bricht vor jeder Änderung am Live-Bestand ab.
            $databaseSource = $this->resolveReadableBackupPart('Datenbank-Dump', $record, $databasePath, $workspace)['path'];
            $filesSource = $this->resolveReadableBackupPart('Datei-Archiv', $record, $filesPath, $workspace)['path'];

            if ($filesSource !== '') {
                $this->assertRestorableFilesArchive($filesSource);
            }

            $rollbackBackup = $this->createFullBackup();
            if (empty($rollbackBackup['success']) || empty($rollbackBackup['name'])) {
                throw new \RuntimeException('Vor der Wiederherstellung konnte kein Sicherungs-Snapshot erstellt werden.');
            }
            $rollbackName = (string) $rollbackBackup['name'];

            if ($databaseSource !== '') {
                $this->restoreDatabaseDump($databaseSource);
                $restoredDatabase = true;
            }

            if ($filesSource !== '') {
                $this->restoreFilesArchive($filesSource);
                $restoredFiles = true;
            }
        } finally {
            $this->removeDecryptWorkspace($workspace);
        }

        return [
            'success' => true,
            'restored_database' => $restoredDatabase,
            'restored_files' => $restoredFiles,
            'rollback_backup' => $rollbackName,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validateBackup(string $backupName, bool $includeRestoreDryRun = false): array
    {
        $record = $this->findBackupRecord($backupName);
        if ($record === null) {
            throw new \RuntimeException('Backup wurde nicht gefunden.');
        }

        $databasePath = $this->resolveBackupDatabasePath($record);
        $filesPath = $this->resolveBackupFilesArchivePath($record);
        $criticalTables = $this->getCriticalTablesForValidation();

        $checks = [];
        $tableProbes = [];
        $restoreDryRun = [
            'requested' => $includeRestoreDryRun,
            'performed' => false,
            'status' => 'skipped',
            'message' => $includeRestoreDryRun
                ? 'Restore-Dry-Run wurde noch nicht ausgeführt.'
                : 'Restore-Dry-Run wurde nicht angefordert.',
            'tables' => [],
        ];

        $checks[] = $this->buildManifestValidationCheck($record);
        $workspace = null;

        try {
            if ($databasePath !== '') {
                [$databaseHashCheck, $resolvedDatabaseHash] = $this->buildFileHashValidationCheck(
                    'Datenbank-Dump SHA-256',
                    $databasePath,
                    (string) ($record['checksums']['database']['value'] ?? '')
                );
                $checks[] = $databaseHashCheck;
                [$readableDatabasePath, $decryptionCheck] = $this->buildDecryptionValidationCheck('Datenbank-Dump', $record, $databasePath, $workspace);
                if ($decryptionCheck !== null) {
                    $checks[] = $decryptionCheck;
                }

                if ($readableDatabasePath !== '') {
                    $tableProbes = $this->probeBackupTables($readableDatabasePath, $criticalTables);

                    if ($includeRestoreDryRun) {
                        $restoreDryRun = $this->runTemporaryDatabaseRestoreCheck($readableDatabasePath, $criticalTables, $resolvedDatabaseHash);
                    }
                } elseif ($includeRestoreDryRun) {
                    $restoreDryRun = [
                        'requested' => true,
                        'performed' => false,
                        'status' => 'blocked',
                        'message' => 'Restore-Dry-Run nicht möglich, da der Datenbank-Dump nicht entschlüsselt werden konnte.',
                        'tables' => [],
                    ];
                }
            } else {
                $checks[] = [
                    'label' => 'Datenbank-Dump',
                    'status' => 'blocked',
                    'current' => 'Fehlt',
                    'expected' => 'Lesbar',
                    'detail' => 'Dieses Backup enthält keinen lesbaren Datenbank-Dump.',
                ];

                if ($includeRestoreDryRun) {
                    $restoreDryRun = [
                        'requested' => true,
                        'performed' => false,
                        'status' => 'blocked',
                        'message' => 'Restore-Dry-Run nicht möglich, da kein Datenbank-Dump im Backup enthalten ist.',
                        'tables' => [],
                    ];
                }
            }

            if ($filesPath !== '') {
                [$filesHashCheck] = $this->buildFileHashValidationCheck(
                    'Datei-Archiv SHA-256',
                    $filesPath,
                    (string) ($record['checksums']['files']['value'] ?? '')
                );
                $checks[] = $filesHashCheck;
                [$readableFilesPath, $decryptionCheck] = $this->buildDecryptionValidationCheck('Datei-Archiv', $record, $filesPath, $workspace);
                if ($decryptionCheck !== null) {
                    $checks[] = $decryptionCheck;
                }
                if ($readableFilesPath !== '') {
                    $checks[] = $this->buildFilesArchiveValidationCheck($readableFilesPath);
                }
            }
        } finally {
            $this->removeDecryptWorkspace($workspace);
        }

        return [
            'backup' => [
                'name' => (string) ($record['name'] ?? $backupName),
                'type' => (string) ($record['type'] ?? 'backup'),
                'date' => (string) ($record['date'] ?? ''),
                'size_formatted' => isset($record['size']) ? $this->formatBytes((int) $record['size']) : '-',
                'cms_version' => (string) ($record['cms_version'] ?? 'unknown'),
                'database' => basename((string) ($record['database'] ?? '')),
                'files' => basename((string) ($record['files'] ?? '')),
                'encrypted' => $this->getRecordEncryption($record) !== null,
            ],
            'summary' => $this->summarizeValidationStatuses($checks, $tableProbes, $restoreDryRun),
            'checks' => $checks,
            'table_probes' => $tableProbes,
            'restore_dry_run' => $restoreDryRun,
        ];
    }
    
    /**
     * Delete backup
     */
    public function deleteBackup(string $backupName): bool
    {
        try {
            $normalizedName = trim(basename($backupName));
            if ($normalizedName === '') {
                return false;
            }

            $backupPath = $this->backupRoot() . $normalizedName;

            if ($this->isLegacyDatabaseBackupFile($normalizedName) && is_file($backupPath) && $this->isWithinBackupRoot($backupPath)) {
                return unlink($backupPath);
            }

            $normalizedName = $this->normalizeBackupName($normalizedName);
            $backupPath = $this->backupRoot() . $normalizedName;

            if (!is_dir($backupPath) || !$this->isWithinBackupRoot($backupPath)) {
                return false;
            }

            // Nur erkannte Backups löschen – CMS_BACKUP_PATH kann auf ein gemeinsam genutztes Verzeichnis zeigen.
            if ($this->readBackupRecord($normalizedName) === null) {
                return false;
            }

            return $this->deleteDirectory($backupPath);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup')->warning('Backup could not be deleted.', [
                'backup_name' => $backupName,
                'exception' => $e,
            ]);
            return false;
        }
    }
    
    /**
     * Delete directory recursively
     */
    private function deleteDirectory(string $dir): bool
    {
        $normalizedDir = rtrim($dir, '\\/');

        if (!is_dir($normalizedDir) || !$this->isWithinBackupRoot($normalizedDir)) {
            return false;
        }
        
        $files = scandir($normalizedDir);
        if ($files === false) {
            return false;
        }
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $normalizedDir . DIRECTORY_SEPARATOR . $file;
            if (is_link($path) || is_file($path)) {
                if (!unlink($path)) {
                    return false;
                }

                continue;
            }

            if (is_dir($path) && !$this->deleteDirectory($path)) {
                return false;
            }
        }
        
        return rmdir($normalizedDir);
    }

    /** @return array<string, mixed>|null */
    private function findBackupRecord(string $backupName): ?array
    {
        $normalizedName = trim(basename($backupName));
        if ($normalizedName === '') {
            return null;
        }

        // Direkt lesen statt über listBackups(): auch Backups jenseits des Listen-Limits bleiben erreichbar.
        return $this->readBackupRecord($normalizedName);
    }

    private function normalizeDownloadPart(string $part): string
    {
        $part = strtolower(trim($part));

        return in_array($part, self::ALLOWED_DOWNLOAD_PARTS, true) ? $part : 'database';
    }

    /** @param array<string, mixed> $record */
    private function resolveBackupDatabasePath(array $record): string
    {
        $name = trim(basename((string) ($record['name'] ?? '')));
        if ($name !== '' && $this->isLegacyDatabaseBackupFile($name)) {
            $path = $this->backupRoot() . $name;

            return is_file($path) ? $path : '';
        }

        $databaseFile = trim(basename((string) ($record['database'] ?? '')));
        if ($name === '' || $databaseFile === '') {
            return '';
        }

        $path = $this->backupRoot() . $name . DIRECTORY_SEPARATOR . $databaseFile;

        return is_file($path) ? $path : '';
    }

    /** @param array<string, mixed> $record */
    private function resolveBackupFilesArchivePath(array $record): string
    {
        $name = trim(basename((string) ($record['name'] ?? '')));
        $filesArchive = trim(basename((string) ($record['files'] ?? '')));
        if ($name === '' || $filesArchive === '') {
            return '';
        }

        $path = $this->backupRoot() . $name . DIRECTORY_SEPARATOR . $filesArchive;

        return is_file($path) ? $path : '';
    }

    private function restoreDatabaseDump(string $filePath): void
    {
        if (!$this->isWithinBackupRoot($filePath) || !is_file($filePath) || !is_readable($filePath)) {
            throw new \RuntimeException('Backup-Dump ist nicht lesbar.');
        }

        $this->executeSqlBackupFileOnConnection($this->db->getPdo(), $filePath);
    }

    private function restoreFilesArchive(string $archivePath): void
    {
        if (!$this->isWithinBackupRoot($archivePath) || !is_file($archivePath) || !is_readable($archivePath)) {
            throw new \RuntimeException('Backup-Archiv ist nicht lesbar.');
        }

        if (!extension_loaded('zip')) {
            throw new \RuntimeException('ZIP-Unterstützung ist für die Dateiwiederherstellung nicht verfügbar.');
        }

        $zip = new \ZipArchive();
        $zipResult = $zip->open($archivePath);
        if ($zipResult !== true) {
            throw new \RuntimeException('Backup-Archiv konnte nicht geöffnet werden.');
        }

        $inspection = $this->inspectRestoreArchive($zip);
        if (!$inspection['valid']) {
            $zip->close();
            throw new \RuntimeException('Backup-Archiv kann nicht wiederhergestellt werden: ' . $inspection['reason']);
        }

        $freeSpaceError = $this->checkRestoreFreeSpace($inspection['uncompressed_bytes']);
        if ($freeSpaceError !== '') {
            $zip->close();
            throw new \RuntimeException($freeSpaceError);
        }

        $stagingRoot = $this->createManagedTemporaryDirectory(ABSPATH, '365cms_restore_stage_');
        $rollbackRoot = $this->createManagedTemporaryDirectory(ABSPATH, '365cms_restore_rollback_');

        try {
            if (!$zip->extractTo($stagingRoot)) {
                throw new \RuntimeException('Backup-Archiv konnte nicht in das Staging-Verzeichnis entpackt werden.');
            }
        } finally {
            $zip->close();
        }

        $sourceRoot = $this->resolveSafeRestoreArchiveRoot($stagingRoot, $this->resolveRestoreArchiveRoot($stagingRoot));
        if ($sourceRoot === '') {
            $this->removeDirectory($stagingRoot);
            $this->removeDirectory($rollbackRoot);
            throw new \RuntimeException('Backup-Archiv enthält keine sichere Restore-Struktur.');
        }

        $entries = $this->listDirectoryEntries($sourceRoot);
        if ($entries === []) {
            $this->removeDirectory($stagingRoot);
            $this->removeDirectory($rollbackRoot);
            throw new \RuntimeException('Backup-Archiv enthält keine wiederherstellbaren Dateien.');
        }

        $backedUpTargets = [];
        $restoredTargets = [];

        try {
            foreach ($entries as $entry) {
                if (in_array($entry, self::RESTORE_SKIPPED_LEGACY_DIRECTORIES, true)) {
                    continue;
                }

                if (!in_array($entry, self::FILE_BACKUP_DIRECTORIES, true)) {
                    throw new \RuntimeException('Backup-Archiv enthält nicht erlaubte Zielpfade.');
                }

                $sourcePath = $sourceRoot . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($sourcePath)) {
                    throw new \RuntimeException('Backup-Archiv enthält einen ungültigen Verzeichniseintrag.');
                }

                $targetPath = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . $entry;
                $rollbackPath = $rollbackRoot . DIRECTORY_SEPARATOR . $entry;

                if (file_exists($targetPath)) {
                    if (!rename($targetPath, $rollbackPath)) {
                        throw new \RuntimeException('Bestehendes Zielverzeichnis konnte nicht in das Rollback verschoben werden: ' . $entry);
                    }

                    $backedUpTargets[] = $entry;
                }

                if (!rename($sourcePath, $targetPath)) {
                    throw new \RuntimeException('Backup-Dateien konnten nicht in das Ziel verschoben werden: ' . $entry);
                }

                $restoredTargets[] = $entry;
            }
        } catch (\Throwable $e) {
            foreach (array_reverse($restoredTargets) as $entry) {
                $targetPath = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . $entry;
                $stagingPath = $sourceRoot . DIRECTORY_SEPARATOR . $entry;

                if (file_exists($targetPath)) {
                    @rename($targetPath, $stagingPath);
                }
            }

            foreach (array_reverse($backedUpTargets) as $entry) {
                $targetPath = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . $entry;
                $rollbackPath = $rollbackRoot . DIRECTORY_SEPARATOR . $entry;

                if (file_exists($rollbackPath)) {
                    @rename($rollbackPath, $targetPath);
                }
            }

            $this->removeDirectory($stagingRoot);
            $this->removeDirectory($rollbackRoot);
            throw $e;
        }

        $this->removeDirectory($stagingRoot);
        $this->removeDirectory($rollbackRoot);
    }


    /**
     * @param array<string, string> $criticalTables
     * @return array<string, mixed>
     */
    private function runTemporaryDatabaseRestoreCheck(string $databasePath, array $criticalTables, string $databaseHash): array
    {
        $serverPdo = null;
        $restorePdo = null;
        $temporaryDatabase = '';

        try {
            $serverPdo = $this->createServerLevelPdoConnection();
            $temporaryDatabase = $this->generateTemporaryDatabaseName();
            $charset = preg_replace('/[^a-z0-9_]/i', '', defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4') ?: 'utf8mb4';
            $collationValue = defined('DB_COLLATE') ? trim((string) DB_COLLATE) : '';
            $collation = $collationValue !== ''
                ? ' COLLATE ' . (preg_replace('/[^a-z0-9_]/i', '', $collationValue) ?: '')
                : '';

            $serverPdo->exec('CREATE DATABASE `' . $temporaryDatabase . '` CHARACTER SET ' . $charset . $collation);
            $restorePdo = $this->createDatabaseLevelPdoConnection($temporaryDatabase);

            $this->executeSqlBackupFileOnConnection($restorePdo, $databasePath);
            $comparison = $this->buildTemporaryRestoreComparison($restorePdo, $criticalTables);

            return [
                'requested' => true,
                'performed' => true,
                'status' => $comparison['status'],
                'message' => 'Temporärer Restore-Dry-Run erfolgreich durchgeführt.' . ($databaseHash !== '' ? ' Prüfbasis: ' . substr($databaseHash, 0, 16) . '…' : ''),
                'tables' => $comparison['tables'],
            ];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('backup')->warning('Temporärer Restore-Dry-Run konnte nicht abgeschlossen werden.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return [
                'requested' => true,
                'performed' => false,
                'status' => 'warning',
                'message' => 'Restore-Dry-Run konnte nicht vollständig durchgeführt werden: ' . $this->sanitizeValidationMessage($e->getMessage()),
                'tables' => [],
            ];
        } finally {
            $restorePdo = null;

            if ($serverPdo instanceof \PDO && $temporaryDatabase !== '') {
                try {
                    $serverPdo->exec('DROP DATABASE IF EXISTS `' . $temporaryDatabase . '`');
                } catch (\Throwable $cleanupException) {
                    Logger::instance()->withChannel('backup')->warning('Temporäre Restore-Datenbank konnte nicht bereinigt werden.', [
                        'database' => $temporaryDatabase,
                        'exception' => $cleanupException::class,
                    ]);
                }
            }
        }
    }


    private function executeSqlBatchOnConnection(\PDO $pdo, string $sql): void
    {
        $this->executeSqlStreamOnConnection($pdo, [$sql]);
    }

    private function executeSqlBackupFileOnConnection(\PDO $pdo, string $filePath): void
    {
        $this->executeSqlStreamOnConnection($pdo, $this->createBackupLineReader($filePath));
    }

    private function executeSqlStreamOnConnection(\PDO $pdo, iterable $chunks): void
    {
        $statement = '';
        $inSingleQuote = false;
        $inDoubleQuote = false;
        $isEscaped = false;

        foreach ($chunks as $chunk) {
            if (!is_string($chunk) || $chunk === '') {
                continue;
            }

            $length = strlen($chunk);

            for ($i = 0; $i < $length; $i++) {
                $char = $chunk[$i];

                if ($isEscaped) {
                    $statement .= $char;
                    $isEscaped = false;
                    continue;
                }

                if (($inSingleQuote || $inDoubleQuote) && $char === '\\') {
                    $statement .= $char;
                    $isEscaped = true;
                    continue;
                }

                if ($char === "'" && !$inDoubleQuote) {
                    $inSingleQuote = !$inSingleQuote;
                    $statement .= $char;
                    continue;
                }

                if ($char === '"' && !$inSingleQuote) {
                    $inDoubleQuote = !$inDoubleQuote;
                    $statement .= $char;
                    continue;
                }

                if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
                    $trimmed = trim($statement);
                    if ($trimmed !== '') {
                        $pdo->exec($trimmed);
                    }

                    $statement = '';
                    continue;
                }

                $statement .= $char;
            }
        }

        $trimmed = trim($statement);
        if ($trimmed !== '') {
            $pdo->exec($trimmed);
        }
    }

    private function createServerLevelPdoConnection(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;charset=%s',
            DB_HOST,
            defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'
        );

        return new \PDO($dsn, DB_USER, DB_PASS, $this->buildPdoOptions());
    }

    private function createDatabaseLevelPdoConnection(string $databaseName): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            $databaseName,
            defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'
        );

        return new \PDO($dsn, DB_USER, DB_PASS, $this->buildPdoOptions());
    }

    /** @return array<int, mixed> */
    private function buildPdoOptions(): array
    {
        return [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \CMS\Database::mysqlAttribute('ATTR_INIT_COMMAND') => 'SET NAMES ' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4'),
        ];
    }

    private function generateTemporaryDatabaseName(): string
    {
        return 'cms_restore_check_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
    }

    /**
     * @param array<string, string> $criticalTables
     * @return array<string, mixed>
     */
    private function buildTemporaryRestoreComparison(\PDO $restorePdo, array $criticalTables): array
    {
        $rows = [];
        $status = 'ok';

        foreach ($criticalTables as $tableName => $label) {
            $restoredRows = $this->countRowsInConnection($restorePdo, $tableName);
            $liveRows = $this->countRowsInConnection($this->db->getPdo(), $tableName);

            if ($restoredRows === null) {
                $status = 'warning';
                $rows[] = [
                    'table' => $tableName,
                    'label' => $label,
                    'status' => 'warning',
                    'live_rows' => $liveRows,
                    'restored_rows' => null,
                    'detail' => 'Tabelle wurde im temporären Restore nicht gefunden oder konnte nicht gezählt werden.',
                ];
                continue;
            }

            $rowStatus = ($liveRows !== null && $liveRows === $restoredRows) ? 'ok' : 'warning';
            if ($rowStatus === 'warning' && $status !== 'blocked') {
                $status = 'warning';
            }

            $rows[] = [
                'table' => $tableName,
                'label' => $label,
                'status' => $rowStatus,
                'live_rows' => $liveRows,
                'restored_rows' => $restoredRows,
                'detail' => $liveRows === null
                    ? 'Aktueller Live-Zähler ist nicht verfügbar; der Dry-Run-Restore konnte die Tabelle aber erfolgreich lesen.'
                    : ($liveRows === $restoredRows
                        ? 'Live- und Dry-Run-Zähler stimmen überein.'
                        : 'Live- und Dry-Run-Zähler weichen ab; das ist bei älteren Sicherungen möglich und dient als Vergleichshinweis.'),
            ];
        }

        return [
            'status' => $status,
            'tables' => $rows,
        ];
    }

    private function countRowsInConnection(\PDO $pdo, string $tableName): ?int
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $tableName) !== 1) {
            return null;
        }

        try {
            $result = $pdo->query('SELECT COUNT(*) FROM ' . $this->quoteSqlIdentifier($tableName));
            $count = $result !== false ? $result->fetchColumn() : false;

            return $count !== false ? (int) $count : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Prüft ein Datei-Archiv vor dem Entpacken (Pfade, Symlinks, Größen, Zielverzeichnisse).
     *
     * @return array{valid:bool, reason:string, entries:int, uncompressed_bytes:int, skipped_directories:list<string>}
     */
    private function inspectRestoreArchive(\ZipArchive $zip): array
    {
        $entryCount = $zip->numFiles;
        $invalid = static fn (string $reason, int $bytes = 0): array => [
            'valid' => false,
            'reason' => $reason,
            'entries' => $entryCount,
            'uncompressed_bytes' => $bytes,
            'skipped_directories' => [],
        ];

        if ($entryCount <= 0) {
            return $invalid('Das Archiv enthält keine Einträge.');
        }

        if ($entryCount > self::RESTORE_MAX_ARCHIVE_ENTRIES) {
            return $invalid(sprintf('Das Archiv enthält %d Einträge (Grenze: %d).', $entryCount, self::RESTORE_MAX_ARCHIVE_ENTRIES));
        }

        $totalUncompressedSize = 0;
        $firstSegments = [];
        $secondSegments = [];

        for ($index = 0; $index < $entryCount; $index++) {
            $entryName = $zip->getNameIndex($index);
            if (!is_string($entryName) || $entryName === '') {
                return $invalid('Das Archiv enthält einen unlesbaren Eintrag.');
            }

            $normalized = str_replace('\\', '/', $entryName);
            $normalized = ltrim($normalized, '/');
            $parts = array_values(array_filter(explode('/', $normalized), static fn (string $part): bool => $part !== ''));

            if ($normalized === ''
                || $normalized !== $entryName
                || str_contains($normalized, '../')
                || str_contains($normalized, '..\\')
                || in_array('..', $parts, true)
                || in_array('.', $parts, true)
                || preg_match('~^[A-Za-z]:/~', $normalized) === 1
                || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1
            ) {
                return $invalid('Das Archiv enthält ungültige oder unsichere Pfade.');
            }

            $stat = $zip->statIndex($index);
            if (is_array($stat)) {
                $size = max(0, (int) ($stat['size'] ?? 0));
                if ($size > self::RESTORE_MAX_ARCHIVE_FILE_BYTES) {
                    return $invalid('Eine Datei im Archiv überschreitet die Restore-Grenze von ' . $this->formatBytes(self::RESTORE_MAX_ARCHIVE_FILE_BYTES) . '.');
                }

                $totalUncompressedSize += $size;
                if ($totalUncompressedSize > self::RESTORE_MAX_ARCHIVE_UNCOMPRESSED_BYTES) {
                    return $invalid('Das Archiv überschreitet entpackt die Restore-Grenze von ' . $this->formatBytes(self::RESTORE_MAX_ARCHIVE_UNCOMPRESSED_BYTES) . '.');
                }
            }

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($index, $opsys, $attributes) && $opsys === \ZipArchive::OPSYS_UNIX) {
                $mode = ($attributes >> 16) & 0xF000;
                if ($mode === 0xA000) {
                    return $invalid('Das Archiv enthält symbolische Links.');
                }
            }

            $isDirectoryEntry = str_ends_with($normalized, '/');
            $firstSegments[$parts[0]] = true;
            if (count($parts) > 1 || $isDirectoryEntry) {
                $secondSegments[$parts[0]][$parts[1] ?? ''] = true;
            } else {
                $secondSegments[$parts[0]]['/file'] = true;
            }
        }

        // Erlaubt sind nur die Backup-Verzeichnisse – direkt oder unter genau einem Wrapper-Ordner.
        $allowedEntries = $this->getRestoreArchiveTopLevelDirectories();
        $topLevel = array_map('strval', array_keys($firstSegments));
        if (count(array_diff($topLevel, $allowedEntries)) > 0) {
            $wrapped = count($topLevel) === 1
                ? array_values(array_filter(array_map('strval', array_keys($secondSegments[$topLevel[0]] ?? [])), static fn (string $segment): bool => $segment !== ''))
                : [];
            if ($wrapped === [] || count(array_diff($wrapped, $allowedEntries)) > 0) {
                return $invalid('Das Archiv enthält nicht erlaubte Zielpfade (erlaubt: ' . implode(', ', self::FILE_BACKUP_DIRECTORIES) . ').', $totalUncompressedSize);
            }

            $topLevel = $wrapped;
        }

        return [
            'valid' => true,
            'reason' => '',
            'entries' => $entryCount,
            'uncompressed_bytes' => $totalUncompressedSize,
            'skipped_directories' => array_values(array_intersect($topLevel, self::RESTORE_SKIPPED_LEGACY_DIRECTORIES)),
        ];
    }

    /** @return list<string> */
    private function getRestoreArchiveTopLevelDirectories(): array
    {
        return array_values(array_merge(self::FILE_BACKUP_DIRECTORIES, self::RESTORE_SKIPPED_LEGACY_DIRECTORIES));
    }

    private function checkRestoreFreeSpace(int $requiredBytes): string
    {
        $freeBytes = @disk_free_space(rtrim((string) ABSPATH, '/\\'));
        if (!is_float($freeBytes) || $requiredBytes <= 0) {
            return '';
        }

        $neededBytes = (int) ceil($requiredBytes * self::RESTORE_FREE_SPACE_FACTOR);
        if ($freeBytes >= $neededBytes) {
            return '';
        }

        return 'Nicht genug freier Speicherplatz für die Wiederherstellung (benötigt ca. ' . $this->formatBytes($neededBytes)
            . ', frei: ' . $this->formatBytes((int) $freeBytes) . ').';
    }

    private function assertBackupPartIntegrity(string $label, string $path, string $expectedHash): void
    {
        if ($path === '' || $expectedHash === '') {
            return;
        }

        $currentHash = $this->calculateFileHash($path);
        if ($currentHash === '' || !hash_equals(strtolower($expectedHash), strtolower($currentHash))) {
            throw new \RuntimeException($label . ': SHA-256 weicht vom Backup-Manifest ab – Datei ist beschädigt oder wurde verändert. Wiederherstellung abgebrochen.');
        }
    }

    private function assertRestorableFilesArchive(string $archivePath): void
    {
        if (!extension_loaded('zip')) {
            throw new \RuntimeException('ZIP-Unterstützung ist für die Dateiwiederherstellung nicht verfügbar.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new \RuntimeException('Backup-Archiv konnte nicht geöffnet werden.');
        }

        try {
            $inspection = $this->inspectRestoreArchive($zip);
        } finally {
            $zip->close();
        }

        if (!$inspection['valid']) {
            throw new \RuntimeException('Backup-Archiv kann nicht wiederhergestellt werden: ' . $inspection['reason']);
        }

        $freeSpaceError = $this->checkRestoreFreeSpace($inspection['uncompressed_bytes']);
        if ($freeSpaceError !== '') {
            throw new \RuntimeException($freeSpaceError);
        }
    }

    private function resolveRestoreArchiveRoot(string $stagingRoot): string
    {
        $entries = $this->listDirectoryEntries($stagingRoot);
        if ($entries === []) {
            return $stagingRoot;
        }

        $allowedEntries = $this->getRestoreArchiveTopLevelDirectories();
        $allAllowed = array_reduce($entries, fn (bool $carry, string $entry): bool => $carry && in_array($entry, $allowedEntries, true), true);
        if ($allAllowed) {
            return $stagingRoot;
        }

        if (count($entries) === 1) {
            $candidate = $stagingRoot . DIRECTORY_SEPARATOR . $entries[0];
            if (is_dir($candidate)) {
                $candidateEntries = $this->listDirectoryEntries($candidate);
                $candidateAllowed = $candidateEntries !== []
                    && array_reduce($candidateEntries, fn (bool $carry, string $entry): bool => $carry && in_array($entry, $allowedEntries, true), true);

                if ($candidateAllowed) {
                    return $candidate;
                }
            }
        }

        return $stagingRoot;
    }

    private function resolveSafeRestoreArchiveRoot(string $stagingRoot, string $candidate): string
    {
        $realStagingRoot = realpath($stagingRoot);
        $realCandidate = realpath($candidate);

        if ($realStagingRoot === false || $realCandidate === false || !is_dir($realCandidate) || is_link($realCandidate)) {
            return '';
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $realStagingRoot), '/') . '/';
        $normalizedCandidate = rtrim(str_replace('\\', '/', $realCandidate), '/') . '/';
        if (!str_starts_with($normalizedCandidate, $normalizedRoot)) {
            return '';
        }

        return $this->isDirectoryTreeInsideRootAndLinkFree($realCandidate, $realStagingRoot) ? $realCandidate : '';
    }

    private function isDirectoryTreeInsideRootAndLinkFree(string $directory, string $root): bool
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/') . '/';

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
        } catch (\UnexpectedValueException) {
            return false;
        }

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                return false;
            }

            if ($item->isLink()) {
                return false;
            }

            $realPath = realpath($item->getPathname());
            if ($realPath === false) {
                return false;
            }

            if (!str_starts_with(str_replace('\\', '/', $realPath), $normalizedRoot)) {
                return false;
            }
        }

        return true;
    }

    private function createManagedTemporaryDirectory(string $parentDir, string $prefix): string
    {
        $path = $this->buildManagedTemporaryPath($parentDir, $prefix);
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new \RuntimeException('Temporäres Restore-Verzeichnis konnte nicht erstellt werden.');
        }

        return $path;
    }

    private function buildManagedTemporaryPath(string $parentDir, string $prefix): string
    {
        $parentDir = rtrim($parentDir, '/\\');

        do {
            $path = $parentDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
        } while (file_exists($path));

        return $path;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildBackupChecksums(string $backupPath, string $databaseFile, string $filesFile = ''): array
    {
        $checksums = [];

        $databaseHash = $this->calculateFileHash($backupPath . $databaseFile);
        if ($databaseHash !== '') {
            $checksums['database'] = [
                'algorithm' => self::BACKUP_HASH_ALGORITHM,
                'value' => $databaseHash,
            ];
        }

        if ($filesFile !== '') {
            $filesHash = $this->calculateFileHash($backupPath . $filesFile);
            if ($filesHash !== '') {
                $checksums['files'] = [
                    'algorithm' => self::BACKUP_HASH_ALGORITHM,
                    'value' => $filesHash,
                ];
            }
        }

        return $checksums;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function buildManifestValidationCheck(array $record): array
    {
        $type = (string) ($record['type'] ?? 'backup');
        $hasManifestChecksums = !empty($record['checksums']);

        if ((string) ($record['status'] ?? 'ok') !== 'ok') {
            return [
                'label' => 'Backup-Vollständigkeit',
                'status' => 'blocked',
                'current' => (string) ($record['status'] ?? '') === 'pending' ? 'In Arbeit' : 'Unvollständig',
                'expected' => 'Manifest und alle Sicherungsdateien vorhanden',
                'detail' => (string) ($record['error'] ?? '') !== '' ? (string) $record['error'] : 'Backup ist unvollständig.',
            ];
        }

        if ($type === 'database' && !str_contains((string) ($record['name'] ?? ''), 'backup_')) {
            return [
                'label' => 'Backup-Manifest',
                'status' => 'warning',
                'current' => 'Legacy-Backup',
                'expected' => 'Manifest mit Prüfsummen',
                'detail' => 'Dieses ältere Einzel-Dump-Backup besitzt kein Manifest. Die aktuelle Dateiprüfung läuft trotzdem fail-soft weiter.',
            ];
        }

        return [
            'label' => 'Backup-Manifest',
            'status' => $hasManifestChecksums ? 'ok' : 'warning',
            'current' => $hasManifestChecksums ? 'Mit Prüfsummen' : 'Ohne Prüfsummen',
            'expected' => 'Manifest mit Prüfsummen',
            'detail' => $hasManifestChecksums
                ? 'Das Backup enthält Referenz-Hashes für die Integritätsprüfung.'
                : 'Das Backup-Manifest enthält noch keine Referenz-Hashes; die aktuelle SHA-256 wird nur informativ berechnet.',
        ];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function buildFileHashValidationCheck(string $label, string $path, string $expectedHash = ''): array
    {
        if (!is_file($path) || !is_readable($path) || !$this->isWithinBackupRoot($path)) {
            return [[
                'label' => $label,
                'status' => 'blocked',
                'current' => 'Nicht lesbar',
                'expected' => 'Lesbar',
                'detail' => 'Die Backup-Datei fehlt oder ist nicht lesbar.',
            ], ''];
        }

        $currentHash = $this->calculateFileHash($path);
        if ($currentHash === '') {
            return [[
                'label' => $label,
                'status' => 'blocked',
                'current' => 'Hash fehlgeschlagen',
                'expected' => self::BACKUP_HASH_ALGORITHM,
                'detail' => 'Die SHA-256-Prüfsumme konnte für diese Backup-Datei nicht berechnet werden.',
            ], ''];
        }

        if ($expectedHash !== '') {
            $isValid = hash_equals(strtolower($expectedHash), strtolower($currentHash));

            return [[
                'label' => $label,
                'status' => $isValid ? 'ok' : 'blocked',
                'current' => substr($currentHash, 0, 16) . '…',
                'expected' => substr($expectedHash, 0, 16) . '…',
                'detail' => $isValid
                    ? 'Die berechnete SHA-256 stimmt mit der im Manifest gespeicherten Referenz überein.'
                    : 'Die berechnete SHA-256 weicht von der im Manifest gespeicherten Referenz ab.',
            ], $currentHash];
        }

        return [[
            'label' => $label,
            'status' => 'warning',
            'current' => substr($currentHash, 0, 16) . '…',
            'expected' => 'Keine Referenz',
            'detail' => 'Es ist keine gespeicherte Referenz-Prüfsumme vorhanden. Die aktuelle SHA-256 wurde nur informativ berechnet.',
        ], $currentHash];
    }

    /**
     * @return array<string, string>
     */
    private function buildFilesArchiveValidationCheck(string $archivePath): array
    {
        if (!extension_loaded('zip')) {
            return [
                'label' => 'Datei-Archiv Struktur',
                'status' => 'warning',
                'current' => 'ZIP nicht verfügbar',
                'expected' => 'Archiv lesbar',
                'detail' => 'Die ZIP-Erweiterung fehlt; die Struktur des Datei-Archivs konnte daher nicht geprüft werden.',
            ];
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($archivePath);
        if ($openResult !== true) {
            return [
                'label' => 'Datei-Archiv Struktur',
                'status' => 'blocked',
                'current' => 'Nicht lesbar',
                'expected' => 'Archiv lesbar',
                'detail' => 'Das Datei-Archiv konnte nicht geöffnet werden.',
            ];
        }

        try {
            $inspection = $this->inspectRestoreArchive($zip);
        } finally {
            $zip->close();
        }

        $detail = $inspection['valid']
            ? 'Das Archiv lässt sich öffnen und enthält nur erlaubte Restore-Pfade (' . $this->formatBytes($inspection['uncompressed_bytes']) . ' entpackt).'
            : $inspection['reason'];
        if ($inspection['valid'] && $inspection['skipped_directories'] !== []) {
            $detail .= ' Enthaltene Core-Verzeichnisse (' . implode(', ', $inspection['skipped_directories']) . ') werden beim Restore übersprungen.';
        }

        return [
            'label' => 'Datei-Archiv Struktur',
            'status' => $inspection['valid'] ? 'ok' : 'blocked',
            'current' => $inspection['entries'] . ' Einträge',
            'expected' => 'Nur erlaubte Restore-Pfade',
            'detail' => $detail,
        ];
    }

    /**
     * @param array<string, string> $criticalTables
     * @return array<int, array<string, mixed>>
     */
    private function probeBackupTables(string $databasePath, array $criticalTables): array
    {
        if ($criticalTables === []) {
            return [];
        }

        $states = [];
        foreach ($criticalTables as $tableName => $label) {
            $states[$tableName] = [
                'table' => $tableName,
                'label' => $label,
                'structure_found' => false,
                'insert_statements' => 0,
            ];
        }

        foreach ($this->createBackupLineReader($databasePath) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '') {
                continue;
            }

            foreach ($states as $tableName => &$state) {
                if (!$state['structure_found'] && str_starts_with($trimmed, 'CREATE TABLE `' . $tableName . '`')) {
                    $state['structure_found'] = true;
                    continue;
                }

                if (str_starts_with($trimmed, 'INSERT INTO `' . $tableName . '`')) {
                    $state['insert_statements']++;
                }
            }
            unset($state);
        }

        $report = [];
        foreach ($states as $state) {
            $structureFound = !empty($state['structure_found']);
            $insertStatements = (int) ($state['insert_statements'] ?? 0);
            $report[] = [
                'table' => (string) $state['table'],
                'label' => (string) $state['label'],
                'status' => $structureFound ? 'ok' : 'blocked',
                'current' => $structureFound ? 'Struktur gefunden' : 'Struktur fehlt',
                'expected' => 'CREATE TABLE lesbar',
                'detail' => $structureFound
                    ? 'Probe-Lesen erfolgreich; erkannte INSERT-Statements: ' . $insertStatements . '.'
                    : 'Die Tabellenstruktur wurde im SQL-Dump nicht gefunden.',
            ];
        }

        return $report;
    }

    /**
     * @return array<string, string>
     */
    private function getCriticalTablesForValidation(): array
    {
        $tables = [];
        $allTables = $this->db->get_col('SHOW TABLES');
        $prefix = $this->db->getPrefix();
        $labels = [
            'users' => 'Benutzer',
            'settings' => 'Einstellungen',
            'pages' => 'Seiten',
            'posts' => 'Beiträge',
        ];

        foreach (self::VALIDATION_CRITICAL_TABLE_SUFFIXES as $suffix) {
            $tableName = $prefix . $suffix;
            if (in_array($tableName, $allTables, true)) {
                $tables[$tableName] = $labels[$suffix] ?? $tableName;
            }
        }

        if ($tables !== []) {
            return $tables;
        }

        foreach ($allTables as $tableName) {
            if (!is_string($tableName) || $tableName === '') {
                continue;
            }

            $tables[$tableName] = $tableName;
            if (count($tables) >= self::VALIDATION_MAX_CRITICAL_TABLES) {
                break;
            }
        }

        return $tables;
    }

    /**
     * @return \Generator<int, string>
     */
    private function createBackupLineReader(string $filePath): \Generator
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'gz') {
            $handle = gzopen($filePath, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Komprimierter Backup-Dump konnte nicht zeilenweise gelesen werden.');
            }

            try {
                while (!gzeof($handle)) {
                    $line = gzgets($handle);
                    if ($line === false) {
                        break;
                    }

                    yield $line;
                }
            } finally {
                gzclose($handle);
            }

            return;
        }

        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Backup-Dump konnte nicht zeilenweise gelesen werden.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                yield $line;
            }
        } finally {
            fclose($handle);
        }
    }

    private function calculateFileHash(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $hash = hash_file(self::BACKUP_HASH_ALGORITHM, $path);

        return is_string($hash) ? strtolower($hash) : '';
    }

    /** @return list<string> */
    private function listDirectoryEntries(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return [];
        }

        return array_values(array_filter($entries, static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $pathname = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($pathname);
                continue;
            }

            @unlink($pathname);
        }

        @rmdir($directory);
    }

    /**
     * @param array<int, array<string, mixed>> $checks
     * @param array<int, array<string, mixed>> $tableProbes
     * @param array<string, mixed> $restoreDryRun
     * @return array<string, int|string>
     */
    private function summarizeValidationStatuses(array $checks, array $tableProbes, array $restoreDryRun): array
    {
        $statuses = [];

        foreach ($checks as $check) {
            $statuses[] = (string) ($check['status'] ?? 'warning');
        }

        foreach ($tableProbes as $probe) {
            $statuses[] = (string) ($probe['status'] ?? 'warning');
        }

        if (($restoreDryRun['requested'] ?? false) === true) {
            $statuses[] = (string) ($restoreDryRun['status'] ?? 'warning');
        }

        $blockedCount = count(array_filter($statuses, static fn (string $status): bool => $status === 'blocked'));
        $warningCount = count(array_filter($statuses, static fn (string $status): bool => $status === 'warning'));
        $okCount = count(array_filter($statuses, static fn (string $status): bool => $status === 'ok'));

        return [
            'status' => $blockedCount > 0 ? 'blocked' : ($warningCount > 0 ? 'warning' : 'ok'),
            'blocked_count' => $blockedCount,
            'warning_count' => $warningCount,
            'ok_count' => $okCount,
        ];
    }

    private function sanitizeValidationMessage(string $message): string
    {
        $message = trim(strip_tags($message));

        return function_exists('mb_substr')
            ? mb_substr($message, 0, 180)
            : substr($message, 0, 180);
    }

    private function buildServiceErrorMessage(string $prefix, \Throwable $e): string
    {
        $detail = $this->sanitizeValidationMessage($e->getMessage());

        return $detail !== '' ? $prefix . ' ' . $detail : $prefix;
    }
    
    /**
     * Get directory size
     */
    private function getDirectorySize(string $dir): int
    {
        $size = 0;
        
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isLink()) {
                continue;
            }

            $size += $file->getSize();
        }
        
        return $size;
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) max(0, $bytes);
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return number_format($size, $i === 0 ? 0 : 2, ',', '.') . ' ' . $units[$i];
    }
    
    /**
     * Get MIME type
     */
    private function getMimeType(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'sql' => 'application/sql',
            'gz' => 'application/gzip',
            'zip' => 'application/zip',
            'json' => 'application/json',
        ];
        
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
    
    /**
     * Log backup
     */
    private function logBackup(string $type, string $name, int $size): bool
    {
        $logEntry = [
            'type' => $type,
            'name' => $name,
            'size' => $size,
            'size_formatted' => $this->formatBytes($size),
            'timestamp' => date('Y-m-d H:i:s'),
            'user' => $_SESSION['user_id'] ?? 'System',
            'success' => true,
        ];
        
        // Zufalls-Suffix verhindert Kollisionen (UNIQUE option_name) bei zwei Backups in derselben Sekunde;
        // die Sortierung nach option_name bleibt chronologisch.
        $optionName = 'backup_log_' . time() . '_' . bin2hex(random_bytes(3));
        
        try {
            return $this->db->insert('settings', [
                'option_name' => $optionName,
                'option_value' => json_encode($logEntry),
                'autoload' => 0,
            ]) !== false;
        } catch (\Exception $e) {
            Logger::instance()->withChannel('backup')->warning('Backup metadata could not be persisted.', [
                'type' => $type,
                'name' => $name,
                'size' => $size,
                'exception' => $e,
            ]);
            return false;
        }
    }

    private function backupRoot(): string
    {
        return $this->backupRootPath ??= self::resolveConfiguredBackupRoot();
    }

    /**
     * Backup-Root aus CMS_BACKUP_PATH (config/app.php): absoluter Pfad oder relativ zu ABSPATH
     * (z. B. '../365cms-backups'); leer = ABSPATH/backups/. Liefert immer einen abschließenden Separator.
     */
    public static function resolveConfiguredBackupRoot(): string
    {
        $configured = self::configuredBackupPath();

        if ($configured === '') {
            $path = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_BACKUP_DIRECTORY;
        } elseif (preg_match('#^(?:[/\\\\]|[A-Za-z]:[/\\\\])#', $configured) === 1) {
            $path = $configured;
        } else {
            $path = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . $configured;
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    private static function configuredBackupPath(): string
    {
        if (!defined('CMS_BACKUP_PATH')) {
            return '';
        }

        $value = constant('CMS_BACKUP_PATH');

        return is_string($value) ? trim($value) : '';
    }

    public function getBackupRoot(): string
    {
        $realRoot = $this->normalizeManagedPath($this->backupRoot(), true);

        return $realRoot !== '' ? $realRoot : $this->backupRoot();
    }

    /**
     * true, wenn der Backup-Root unterhalb des CMS-Verzeichnisses oder des DocumentRoot liegt und damit
     * nur durch .htaccess (Apache) bzw. eine passende Server-Regel vor direktem Abruf geschützt ist.
     */
    public function isBackupRootInsideWebroot(): bool
    {
        $root = $this->comparablePath($this->getBackupRoot());
        $webroots = [(string) ABSPATH];
        $documentRoot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($documentRoot !== '') {
            $webroots[] = $documentRoot;
        }

        foreach ($webroots as $webroot) {
            $realWebroot = realpath($webroot);
            if ($realWebroot === false) {
                continue;
            }

            if (str_starts_with($root, $this->comparablePath(rtrim($realWebroot, '/\\') . DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
    }

    public function isEncryptionEnabled(): bool
    {
        return OptionStore::getInstance()->get(self::OPTION_ENCRYPTION, '0') === '1';
    }

    public function setEncryptionEnabled(bool $enabled): void
    {
        if ($enabled && !CryptoService::getInstance()->canEncryptFiles()) {
            throw new \RuntimeException('Backup-Verschlüsselung benötigt die PHP-Erweiterung sodium (libsodium secretstream).');
        }

        $this->db->execute(
            "INSERT INTO {$this->db->getPrefix()}settings (option_name, option_value, autoload) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            [self::OPTION_ENCRYPTION, $enabled ? '1' : '0']
        );
        OptionStore::getInstance()->flush();
    }

    /**
     * Speicherort und Verschlüsselungsstatus für Admin-Ansicht und Sicherheits-Audit.
     *
     * @return array{path:string, custom_path:bool, inside_webroot:bool, writable:bool, encryption_enabled:bool, encryption_available:bool, dedicated_key:bool, weak_key:bool, key_id:string, unencrypted_backups:int, legacy_webroot_backups:int}
     */
    public function getStorageStatus(): array
    {
        $crypto = CryptoService::getInstance();
        $root = $this->backupRoot();

        return [
            'path' => $this->getBackupRoot(),
            'custom_path' => self::configuredBackupPath() !== '',
            'inside_webroot' => $this->isBackupRootInsideWebroot(),
            'writable' => is_dir($root) && is_writable($root),
            'encryption_enabled' => $this->isEncryptionEnabled(),
            'encryption_available' => $crypto->canEncryptFiles(),
            'dedicated_key' => $crypto->usesDedicatedKey(),
            'weak_key' => $crypto->hasWeakKey(),
            'key_id' => $crypto->currentKeyId(),
            'unencrypted_backups' => $this->countUnencryptedBackups(),
            'legacy_webroot_backups' => $this->countLegacyWebrootBackups(),
        ];
    }

    /**
     * Zählt Backups im aktuellen Backup-Root mit unverschlüsselten Sicherungsdateien.
     */
    private function countUnencryptedBackups(): int
    {
        $count = 0;
        foreach (array_keys($this->collectBackupCandidates()) as $item) {
            $record = $this->readBackupRecord((string) $item);
            if ($record === null || (empty($record['database_available']) && empty($record['files_available']))) {
                continue;
            }

            if ($this->getRecordEncryption($record) === null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Zählt Backups, die nach dem Umzug per CMS_BACKUP_PATH noch im alten Verzeichnis ABSPATH/backups/ liegen.
     */
    private function countLegacyWebrootBackups(): int
    {
        $legacyRoot = rtrim((string) ABSPATH, '/\\') . DIRECTORY_SEPARATOR . self::DEFAULT_BACKUP_DIRECTORY;
        $realLegacyRoot = realpath($legacyRoot);
        if ($realLegacyRoot === false || !is_dir($realLegacyRoot)) {
            return 0;
        }

        if ($this->comparablePath(rtrim($realLegacyRoot, '/\\') . DIRECTORY_SEPARATOR) === $this->comparablePath($this->getBackupRoot())) {
            return 0;
        }

        $count = 0;
        foreach ($this->listDirectoryEntries($realLegacyRoot) as $entry) {
            $path = $realLegacyRoot . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                continue;
            }

            if ((is_dir($path) && (preg_match(self::OWN_BACKUP_DIRECTORY_PATTERN, $entry) === 1 || is_file($path . DIRECTORY_SEPARATOR . 'manifest.json')))
                || (is_file($path) && $this->isLegacyDatabaseBackupFile($entry))
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Lehnt Backup-Roots ab, bei denen „Backup löschen“ oder Restore-Arbeitsverzeichnisse fremde Daten
     * treffen könnten: Dateisystem-Wurzel, das CMS-Verzeichnis selbst oder eines seiner Elternverzeichnisse.
     */
    private function assertSafeBackupRoot(string $backupRoot): void
    {
        $realRoot = $this->prospectiveRealPath($backupRoot);
        if ($realRoot === null) {
            throw new \RuntimeException('Backup-Verzeichnis ist nicht erreichbar (CMS_BACKUP_PATH prüfen).');
        }

        $root = $this->comparablePath(rtrim($realRoot, '/\\') . DIRECTORY_SEPARATOR);
        $realAbspath = realpath((string) ABSPATH);
        $cmsRoot = $realAbspath !== false ? $this->comparablePath(rtrim($realAbspath, '/\\') . DIRECTORY_SEPARATOR) : '';

        if (dirname($realRoot) === $realRoot || ($cmsRoot !== '' && str_starts_with($cmsRoot, $root))) {
            throw new \RuntimeException('CMS_BACKUP_PATH darf weder die Dateisystem-Wurzel noch das CMS-Verzeichnis oder ein übergeordnetes Verzeichnis sein.');
        }

        // uploads/, themes/, plugins/ ersetzt der Datei-Restore, Core-Verzeichnisse das Update.
        if ($cmsRoot !== '' && str_starts_with($root, $cmsRoot) && !str_starts_with($root, $cmsRoot . self::DEFAULT_BACKUP_DIRECTORY . '/')) {
            throw new \RuntimeException('CMS_BACKUP_PATH innerhalb des CMS-Verzeichnisses ist nur unterhalb von backups/ zulässig – andere Verzeichnisse überschreiben Restore und Updates.');
        }
    }

    /**
     * Realer Pfad eines evtl. noch nicht vorhandenen Verzeichnisses: nächster vorhandener Vorfahre per
     * realpath() plus die fehlenden Segmente. null, wenn ein fehlendes Segment „..“ ist oder nichts auflösbar ist.
     */
    private function prospectiveRealPath(string $path): ?string
    {
        $current = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
        $missing = [];

        while ($current !== '' && !is_dir($current)) {
            $segment = basename($current);
            if ($segment === '..') {
                return null;
            }
            if ($segment !== '' && $segment !== '.') {
                array_unshift($missing, $segment);
            }

            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }

        $real = realpath($current === '' ? DIRECTORY_SEPARATOR : $current);
        if ($real === false) {
            return null;
        }

        return $missing === [] ? $real : rtrim($real, '/\\') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $missing);
    }

    private function comparablePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    /**
     * Verschlüsselungs-Metadaten für ein neues Backup oder null, wenn die Verschlüsselung aus ist.
     * Bei aktivierter Option ohne libsodium wird abgebrochen statt unverschlüsselt zu sichern.
     *
     * @return array{format:string, cipher:string, key_id:string}|null
     */
    private function resolveEncryptionForNewBackup(): ?array
    {
        if (!$this->isEncryptionEnabled()) {
            return null;
        }

        $crypto = CryptoService::getInstance();
        if (!$crypto->canEncryptFiles()) {
            throw new \RuntimeException('Backup-Verschlüsselung ist aktiviert, aber libsodium (secretstream) fehlt – es wurde kein unverschlüsseltes Backup erstellt.');
        }

        return [
            'format' => self::ENCRYPTION_FORMAT,
            'cipher' => 'XChaCha20-Poly1305 (libsodium secretstream)',
            'key_id' => $crypto->currentKeyId(),
        ];
    }

    /**
     * Verschlüsselt eine Backup-Datei nach <datei>.enc und entfernt anschließend den Klartext.
     */
    private function encryptBackupPart(string $backupPath, string $fileName): string
    {
        if ($fileName === '') {
            return '';
        }

        $source = $backupPath . $fileName;
        $target = $source . self::ENCRYPTED_FILE_SUFFIX;

        try {
            CryptoService::getInstance()->encryptFile($source, $target, CryptoService::CONTEXT_BACKUP);
        } catch (\Throwable $e) {
            @unlink($target);
            throw new \RuntimeException('Backup-Datei konnte nicht verschlüsselt werden: ' . $e->getMessage(), 0, $e);
        }

        $size = @filesize($target);
        if (!is_int($size) || $size < 1) {
            @unlink($target);
            throw new \RuntimeException('Verschlüsselte Backup-Datei ist leer oder nicht lesbar.');
        }

        if (!@unlink($source) && is_file($source)) {
            throw new \RuntimeException('Unverschlüsselte Backup-Datei konnte nach dem Verschlüsseln nicht entfernt werden.');
        }

        return basename($target);
    }

    /**
     * @param array<string, mixed> $record
     * @return array{format:string, key_id:string}|null
     */
    private function getRecordEncryption(array $record): ?array
    {
        $encryption = $record['encryption'] ?? null;
        if (!is_array($encryption)) {
            return null;
        }

        return [
            'format' => (string) ($encryption['format'] ?? ''),
            'key_id' => (string) ($encryption['key_id'] ?? ''),
        ];
    }

    /**
     * Liefert einen lesbaren Pfad für einen Backup-Teil. Verschlüsselte Dateien (Magic-Bytes bzw. Manifest)
     * werden vollständig und authentifiziert in ein Arbeitsverzeichnis im Backup-Root entschlüsselt.
     *
     * @param array<string, mixed> $record
     * @return array{path:string, decrypted:bool, key_id:string}
     */
    private function resolveReadableBackupPart(string $label, array $record, string $path, ?string &$workspace): array
    {
        if ($path === '') {
            return ['path' => '', 'decrypted' => false, 'key_id' => ''];
        }

        $crypto = CryptoService::getInstance();
        $fileKeyId = $crypto->fileKeyId($path);
        $manifestEncryption = $this->getRecordEncryption($record);

        if ($fileKeyId === null) {
            if ($manifestEncryption !== null) {
                throw new \RuntimeException($label . ': Das Manifest weist das Backup als verschlüsselt aus, die Datei ist es aber nicht – Datei wurde ersetzt oder ist beschädigt.');
            }

            return ['path' => $path, 'decrypted' => false, 'key_id' => ''];
        }

        if (!$crypto->hasKey($fileKeyId)) {
            throw new \RuntimeException($label . ': Schlüssel ' . $fileKeyId . ' fehlt – CMS_ENCRYPTION_KEY bzw. CMS_ENCRYPTION_KEYS_PREVIOUS der sichernden Installation in config/app.php eintragen.');
        }

        $workspace ??= $this->createDecryptWorkspace();
        // Name ohne .enc behalten, damit .sql.gz/.zip beim Lesen erkannt werden (eigenes Verzeichnis, keine Kollision).
        $fileName = basename($path);
        $plainName = str_ends_with(strtolower($fileName), self::ENCRYPTED_FILE_SUFFIX)
            ? substr($fileName, 0, -strlen(self::ENCRYPTED_FILE_SUFFIX))
            : $fileName;
        if ($plainName === '') {
            $plainName = 'backup-part.bin';
        }
        $target = $workspace . DIRECTORY_SEPARATOR . $plainName;

        try {
            $crypto->decryptFile($path, $target, CryptoService::CONTEXT_BACKUP);
        } catch (\Throwable $e) {
            throw new \RuntimeException($label . ' konnte nicht entschlüsselt werden: ' . $e->getMessage(), 0, $e);
        }

        return ['path' => $target, 'decrypted' => true, 'key_id' => $fileKeyId];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{0: string, 1: array<string, string>|null} lesbarer Pfad ('' bei Fehler) und optionaler Check
     */
    private function buildDecryptionValidationCheck(string $label, array $record, string $path, ?string &$workspace): array
    {
        try {
            $readable = $this->resolveReadableBackupPart($label, $record, $path, $workspace);
        } catch (\Throwable $e) {
            return ['', [
                'label' => $label . ' Entschlüsselung',
                'status' => 'blocked',
                'current' => 'Fehlgeschlagen',
                'expected' => 'Entschlüsselt und authentifiziert',
                'detail' => $this->sanitizeValidationMessage($e->getMessage()),
            ]];
        }

        if (!$readable['decrypted']) {
            return [$readable['path'], null];
        }

        return [$readable['path'], [
            'label' => $label . ' Entschlüsselung',
            'status' => 'ok',
            'current' => 'Schlüssel ' . $readable['key_id'],
            'expected' => 'Entschlüsselt und authentifiziert',
            'detail' => 'XChaCha20-Poly1305: alle Blöcke authentifiziert, Datei vollständig. Die entschlüsselte Kopie wurde nach der Prüfung gelöscht.',
        ]];
    }

    private function createDecryptWorkspace(): string
    {
        $this->cleanupStaleDecryptWorkspaces();

        $path = $this->buildManagedTemporaryPath($this->backupRoot(), self::DECRYPT_WORKSPACE_PREFIX);
        if (!@mkdir($path, 0700) && !is_dir($path)) {
            throw new \RuntimeException('Arbeitsverzeichnis zum Entschlüsseln konnte nicht erstellt werden.');
        }

        return $path;
    }

    private function removeDecryptWorkspace(?string $workspace): void
    {
        if ($workspace === null || $workspace === '' || !str_starts_with(basename($workspace), self::DECRYPT_WORKSPACE_PREFIX)) {
            return;
        }

        $this->removeDirectory($workspace);
    }

    /**
     * Entfernt Entschlüsselungs-Arbeitsverzeichnisse abgebrochener Läufe (z. B. Zeitlimit).
     */
    private function cleanupStaleDecryptWorkspaces(): void
    {
        $backupRoot = $this->backupRoot();
        foreach ($this->listDirectoryEntries($backupRoot) as $entry) {
            if (!str_starts_with($entry, self::DECRYPT_WORKSPACE_PREFIX)) {
                continue;
            }

            $path = $backupRoot . $entry;
            if (!is_dir($path) || is_link($path)) {
                continue;
            }

            $modifiedAt = (int) (@filemtime($path) ?: 0);
            if ($modifiedAt > 0 && (time() - $modifiedAt) < self::DECRYPT_WORKSPACE_MAX_AGE_SECONDS) {
                continue;
            }

            $this->removeDirectory($path);
        }
    }

    private function resolveTargetDirectory(?string $targetDir): string
    {
        if ($targetDir === null || trim($targetDir) === '') {
            return $this->backupRoot();
        }

        $normalizedDir = $this->normalizeManagedPath($targetDir, false);
        if (!$this->isWithinBackupRoot($normalizedDir)) {
            throw new \RuntimeException('Ungültiges Backup-Zielverzeichnis.');
        }

        if (!is_dir($normalizedDir) && !mkdir($normalizedDir, 0755, true) && !is_dir($normalizedDir)) {
            throw new \RuntimeException('Backup-Zielverzeichnis konnte nicht erstellt werden.');
        }

        return $normalizedDir;
    }

    /**
     * Legt ein neues Backup-Unterverzeichnis exklusiv an. Existiert der Name bereits (zwei Backups in
     * derselben Sekunde, z. B. Doppelklick oder Rollback-Snapshot), wird ein Zähler-Suffix angehängt,
     * statt das vorhandene Backup zu überschreiben.
     *
     * @return array{name:string, path:string}
     */
    private function createBackupDirectory(string $backupName): array
    {
        $baseName = $this->normalizeBackupName($backupName);
        $backupRoot = $this->backupRoot();

        for ($attempt = 1; $attempt <= self::MAX_BACKUP_NAME_ATTEMPTS; $attempt++) {
            $name = $attempt === 1 ? $baseName : $this->normalizeBackupName($baseName . '-' . $attempt);
            $backupPath = $backupRoot . $name . DIRECTORY_SEPARATOR;
            if (!$this->isWithinBackupRoot($backupPath)) {
                throw new \RuntimeException('Backup-Unterverzeichnis liegt außerhalb des Backup-Roots.');
            }

            if (file_exists($backupPath)) {
                continue;
            }

            if (@mkdir($backupPath, 0755)) {
                return ['name' => $name, 'path' => $backupPath];
            }

            if (!file_exists($backupPath)) {
                throw new \RuntimeException('Backup-Unterverzeichnis konnte nicht erstellt werden.');
            }
        }

        throw new \RuntimeException('Für das Backup konnte kein freier Verzeichnisname reserviert werden.');
    }

    /**
     * Entfernt das Verzeichnis eines fehlgeschlagenen Backups, damit keine unvollständigen Reste liegen bleiben.
     */
    private function discardIncompleteBackup(?string $backupPath): void
    {
        if ($backupPath === null || $backupPath === '' || !is_dir($backupPath) || !$this->isWithinBackupRoot($backupPath)) {
            return;
        }

        if (rtrim($this->normalizeManagedPath($backupPath, true), '\\/') === rtrim($this->normalizeManagedPath($this->backupRoot(), true), '\\/')) {
            return;
        }

        if (!$this->deleteDirectory($backupPath)) {
            Logger::instance()->withChannel('backup')->warning('Incomplete backup directory could not be removed.', [
                'backup_name' => basename(rtrim($backupPath, '\\/')),
            ]);
        }
    }

    private function getCmsVersion(): string
    {
        return \CMS\Version::CURRENT;
    }

    private function normalizeBackupName(string $name): string
    {
        $name = trim($name);
        if (!$this->isAllowedBackupName($name)) {
            throw new \RuntimeException('Ungültiger Backup-Name.');
        }

        return $name;
    }

    private function isAllowedBackupName(string $name): bool
    {
        return preg_match(self::BACKUP_NAME_PATTERN, $name) === 1;
    }

    private function isLegacyDatabaseBackupFile(string $name): bool
    {
        return preg_match(self::LEGACY_DATABASE_BACKUP_PATTERN, trim(basename($name))) === 1;
    }

    private function isWithinBackupRoot(string $path): bool
    {
        $backupRoot = $this->normalizeManagedPath($this->backupRoot(), true);
        $normalizedPath = $this->normalizeManagedPath($path, file_exists($path));

        if ($backupRoot === '' || $normalizedPath === '') {
            return false;
        }

        return $normalizedPath === $backupRoot
            || str_starts_with($normalizedPath, $backupRoot);
    }

    private function normalizeManagedPath(string $path, bool $mustExist): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $resolved = realpath($path);
        if (is_string($resolved)) {
            return rtrim($resolved, '\\/') . DIRECTORY_SEPARATOR;
        }

        if ($mustExist) {
            return '';
        }

        $parent = dirname($path);
        $resolvedParent = realpath($parent);
        if (!is_string($resolvedParent) || $resolvedParent === '') {
            return '';
        }

        return rtrim($resolvedParent, '\\/') . DIRECTORY_SEPARATOR . basename($path) . DIRECTORY_SEPARATOR;
    }

    /** @param array<string, mixed> $config */
    private function isValidS3RestConfig(array $config): bool
    {
        $bucket = strtolower(trim((string) ($config['bucket'] ?? '')));
        $endpoint = strtolower(trim((string) ($config['endpoint'] ?? '')));

        if ($bucket === '' || preg_match(self::S3_BUCKET_PATTERN, $bucket) !== 1) {
            return false;
        }

        if ($endpoint === '' || preg_match(self::S3_ENDPOINT_PATTERN, $endpoint) !== 1) {
            return false;
        }

        if (str_contains($endpoint, '/') || str_contains($endpoint, '@') || str_contains($endpoint, ':')) {
            return false;
        }

        $region = strtolower(trim((string) ($config['region'] ?? '')));
        if ($region === '' || preg_match(self::S3_REGION_PATTERN, $region) !== 1) {
            return false;
        }

        foreach (['access_key', 'secret_key'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }
}

}
