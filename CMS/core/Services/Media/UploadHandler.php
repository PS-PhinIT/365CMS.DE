<?php
declare(strict_types=1);

namespace CMS\Services\Media;

use CMS\Auth;
use CMS\Contracts\LoggerInterface;
use CMS\Logger;
use CMS\WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class UploadHandler
{
    private const MAX_STORED_FILENAME_LENGTH = 180;
    private const MAX_EXTENSION_LENGTH = 16;
    private const PUBLIC_FILE_MODE = 0644;
    private const PRIVATE_FILE_MODE = 0640;

    private LoggerInterface $logger;
    private ?\Closure $uploadValidator;

    public function __construct(
        private readonly string $uploadPath,
        private readonly MediaRepository $repository,
        private readonly ImageProcessor $imageProcessor,
        ?\Closure $uploadValidator = null,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? Logger::instance()->withChannel('media.upload');
        $this->uploadValidator = $uploadValidator;
    }

    public function createFolder(string $path, string $name): bool|WP_Error
    {
        $path = trim($path, '/\\');
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..' || str_starts_with($name, '.') || preg_match('#[\\\\/:*?"<>|\x00-\x1F]#', $name) === 1) {
            return new WP_Error('invalid_folder_name', 'Ungültiger Ordnername');
        }

        $basePath = $this->resolvePath($path);
        if ($basePath instanceof WP_Error) {
            return $basePath;
        }

        $folderPath = $basePath . DIRECTORY_SEPARATOR . $name;
        if (is_dir($folderPath)) {
            return new WP_Error('folder_exists', 'Ordner existiert bereits');
        }

        if (!is_dir($basePath)) {
            return new WP_Error('parent_not_found', 'Übergeordnetes Verzeichnis nicht gefunden');
        }

        if (!is_writable($basePath)) {
            return new WP_Error('parent_not_writable', 'Übergeordnetes Verzeichnis ist nicht beschreibbar');
        }

        if (mkdir($folderPath, 0755) || is_dir($folderPath)) {
            return true;
        }

        $this->logFilesystemFailure('mkdir', 'Ordner konnte nicht erstellt werden', [
            'path' => $folderPath,
        ]);

        return new WP_Error('folder_create_failed', 'Ordner konnte nicht erstellt werden');
    }

    public function uploadFile(array $file, string $path = '', bool $convertImagesToWebP = false, ?array $validationSettings = null): array|WP_Error
    {
        $validation = $this->validateFile($file, $validationSettings);
        if ($validation instanceof WP_Error) {
            return $validation;
        }

        $effectivePath = $this->resolveManagedUploadPath($path, $validationSettings);
        $targetDir = $this->resolvePath($effectivePath);
        if ($targetDir instanceof WP_Error) {
            return $targetDir;
        }

        $directoryReady = $this->ensureDirectoryExists($targetDir, 'upload_dir_missing', 'Upload-Verzeichnis konnte nicht erstellt werden');
        if ($directoryReady instanceof WP_Error) {
            return $directoryReady;
        }

        $originalName = $file['name'];
        $sanitizedName = $this->sanitizeFileName($originalName, $validationSettings ?? []);
        $targetPath = $this->createTargetPath(
            $targetDir,
            $sanitizedName,
            (bool) (($validationSettings['unique_filenames'] ?? true) === true)
        );
        if ($targetPath instanceof WP_Error) {
            return $targetPath;
        }
        $sourcePath = (string) $file['tmp_name'];

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            $this->logFilesystemFailure('preflight', 'Temporäre Upload-Datei ist nicht lesbar', [
                'source' => $sourcePath,
                'target' => $targetPath,
            ]);

            return new WP_Error('invalid_upload_source', 'Temporäre Upload-Datei ist nicht verfügbar');
        }

        if (!$this->moveUploadedFileToTarget($sourcePath, $targetPath)) {
            $this->logFilesystemFailure('upload_move', 'Datei konnte nicht verschoben werden', [
                'source' => $sourcePath,
                'target' => $targetPath,
                'is_uploaded_file' => is_uploaded_file($sourcePath),
            ]);

            return new WP_Error('upload_move_failed', 'Datei konnte nicht verschoben werden');
        }

        $relativePath = trim(($effectivePath !== '' ? trim($effectivePath, '/\\') . '/' : '') . basename($targetPath), '/');
        @chmod($targetPath, $this->resolveUploadFileMode($relativePath));

        $category = $this->repository->detectSystemCategory($relativePath);
        $meta = $this->repository->loadMeta();
        $currentUser = Auth::getCurrentUser();
        $meta['files'][str_replace('\\', '/', $relativePath)] = [
            'uploaded_at' => date('c'),
            'uploaded_by' => (string)($currentUser->username ?? $currentUser->email ?? 'system'),
            'uploader_id' => isset($currentUser->id) ? (int)$currentUser->id : null,
            'original_name' => $originalName,
            'category' => $category,
        ];
        $this->repository->saveMeta($meta);

        if ($convertImagesToWebP && $this->isConvertibleImage($targetPath)) {
            $this->imageProcessor->convertToWebP($targetPath);
        }

        return [
            'name' => basename($targetPath),
            'path' => $relativePath,
            'size' => filesize($targetPath),
            'type' => mime_content_type($targetPath),
            'url' => $this->repository->buildPublicUrl($relativePath),
            'category' => $category,
        ];
    }

    public function moveFile(string $sourcePath, string $targetPath): bool|WP_Error
    {
        $sourceFullPath = $this->resolvePath($sourcePath);
        $targetFullPath = $this->resolvePath($targetPath);

        if ($sourceFullPath instanceof WP_Error) {
            return $sourceFullPath;
        }
        if ($targetFullPath instanceof WP_Error) {
            return $targetFullPath;
        }
        if (!file_exists($sourceFullPath)) {
            return new WP_Error('source_not_found', 'Quelle nicht gefunden');
        }

        $targetDir = dirname($targetFullPath);
        $directoryReady = $this->ensureDirectoryExists($targetDir, 'target_dir_missing', 'Zielverzeichnis konnte nicht erstellt werden');
        if ($directoryReady instanceof WP_Error) {
            return $directoryReady;
        }

        if (!is_writable(dirname($sourceFullPath)) || !is_writable($targetDir)) {
            return new WP_Error('move_not_writable', 'Quell- oder Zielverzeichnis ist nicht beschreibbar');
        }

        if (!rename($sourceFullPath, $targetFullPath)) {
            $this->logFilesystemFailure('rename', 'Element konnte nicht verschoben werden', [
                'source' => $sourceFullPath,
                'target' => $targetFullPath,
            ]);

            return new WP_Error('move_failed', 'Element konnte nicht verschoben werden');
        }

        if (is_file($targetFullPath)) {
            @chmod($targetFullPath, $this->resolveUploadFileMode($targetPath));
        }

        $this->repository->renameMetaPath($sourcePath, $targetPath);
        return true;
    }

    public function deleteItem(string $path): bool|WP_Error
    {
        $fullPath = $this->resolvePath($path);
        if ($fullPath instanceof WP_Error) {
            return $fullPath;
        }

        if (!file_exists($fullPath)) {
            return new WP_Error('not_found', 'Element nicht gefunden');
        }

        $result = is_dir($fullPath) ? $this->deleteDirectory($fullPath) : $this->deleteFile($fullPath);
        if (!$result) {
            return new WP_Error('delete_failed', 'Element konnte nicht gelöscht werden');
        }

        $this->repository->purgeMetaForPath($path);
        return true;
    }

    public function renameItem(string $path, string $newName): bool|WP_Error
    {
        $fullPath = $this->resolvePath($path);
        if ($fullPath instanceof WP_Error) {
            return $fullPath;
        }

        if (!file_exists($fullPath)) {
            return new WP_Error('not_found', 'Element nicht gefunden');
        }

        $normalizedTargetName = $this->normalizeRenameTargetName($fullPath, $newName);
        if ($normalizedTargetName instanceof WP_Error) {
            return $normalizedTargetName;
        }

        $targetPath = dirname($fullPath) . DIRECTORY_SEPARATOR . $normalizedTargetName;
        if (file_exists($targetPath)) {
            return new WP_Error('target_exists', 'Ziel existiert bereits');
        }

        $parentDir = dirname($fullPath);
        if (!is_writable($parentDir)) {
            return new WP_Error('rename_not_writable', 'Verzeichnis ist nicht beschreibbar');
        }

        if (!rename($fullPath, $targetPath)) {
            $this->logFilesystemFailure('rename', 'Element konnte nicht umbenannt werden', [
                'source' => $fullPath,
                'target' => $targetPath,
            ]);

            return new WP_Error('rename_failed', 'Element konnte nicht umbenannt werden');
        }

        $normalizedPath = str_replace('\\', '/', $path);
        $parentRelativePath = trim(dirname($normalizedPath), '/');
        if ($parentRelativePath === '.' || $parentRelativePath === '') {
            $parentRelativePath = '';
        }

        $newRelativePath = ($parentRelativePath !== '' ? $parentRelativePath . '/' : '') . $normalizedTargetName;
        $this->repository->renameMetaPath($path, $newRelativePath);

        return true;
    }

    public function validateFile(array $file, ?array $validationSettings = null): true|WP_Error
    {
        if ($this->uploadValidator instanceof \Closure) {
            $validation = ($this->uploadValidator)($file, $validationSettings);

            return $validation instanceof WP_Error ? $validation : true;
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'Upload fehlgeschlagen');
        }

        if (!isset($file['tmp_name'], $file['name']) || !is_file($file['tmp_name'])) {
            return new WP_Error('invalid_upload', 'Ungültige Upload-Datei');
        }

        $maxSize = 25 * 1024 * 1024;
        if (($file['size'] ?? 0) > $maxSize) {
            return new WP_Error('file_too_large', 'Datei ist zu groß');
        }

        return true;
    }

    private function resolvePath(string $path): string|WP_Error
    {
        $basePath = $this->normalizeFilesystemPath((string) (realpath($this->uploadPath) ?: $this->uploadPath));
        $path = trim(str_replace('\\', '/', $path));

        if ($path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path) === 1)) {
            return new WP_Error('security_violation', 'Absolute Pfade sind nicht erlaubt');
        }

        $path = trim($path, '/');
        if ($path !== '' && preg_match('/(?:^|\/)\.\.?(?:\/|$)|[\x00-\x1F\x7F]/u', $path) === 1) {
            return new WP_Error('security_violation', 'Ungültiger Pfad');
        }

        $fullPath = $basePath;
        if ($path !== '') {
            $fullPath .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        }

        $existingPath = file_exists($fullPath) ? realpath($fullPath) : realpath(dirname($fullPath));
        if (is_string($existingPath) && !$this->isPathInsideBase($existingPath, $basePath)) {
            return new WP_Error('security_violation', 'Pfad außerhalb des Upload-Verzeichnisses');
        }

        return $fullPath;
    }

    private function normalizeFilesystemPath(string $path): string
    {
        return rtrim(str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function isPathInsideBase(string $path, string $basePath): bool
    {
        $normalizedPath = $this->normalizeFilesystemPath($path);
        $normalizedBase = $this->normalizeFilesystemPath($basePath);

        return $normalizedPath === $normalizedBase || str_starts_with($normalizedPath, $normalizedBase . DIRECTORY_SEPARATOR);
    }

    private function sanitizeFileName(string $fileName, array $settings = []): string
    {
        $fileName = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $fileName));

        $name = (string) pathinfo($fileName, PATHINFO_FILENAME);
        $extension = (string) pathinfo($fileName, PATHINFO_EXTENSION);
        $sanitize = (bool) ($settings['sanitize_filenames'] ?? true);
        $lowercase = (bool) ($settings['lowercase_filenames'] ?? false);

        $name = str_replace(['/', '\\'], '-', $name);
        $name = preg_replace('/\s+/u', '-', $name) ?: $name;
        $name = str_replace('.', '-', $name);

        if ($sanitize) {
            $name = preg_replace('/[^A-Za-z0-9_-]+/u', '-', $name) ?: $name;
            $name = preg_replace('/[-_]{2,}/', '-', $name) ?: $name;
        } else {
            $name = preg_replace('/[^A-Za-z0-9_-]+/u', '', $name) ?: $name;
        }

        $name = trim($name, " \t\n\r\0\x0B.-_");
        $extension = preg_replace('/[^A-Za-z0-9]+/', '', $extension) ?: '';
        $extension = substr($extension, 0, self::MAX_EXTENSION_LENGTH);

        if ($lowercase) {
            $name = strtolower($name);
            $extension = strtolower($extension);
        }

        if ($name === '') {
            $name = 'upload';
        }

        $name = $this->limitFileBaseNameLength($name, $extension);

        return $name . ($extension !== '' ? '.' . $extension : '');
    }

    private function createTargetPath(string $directory, string $fileName, bool $allowUniqueSuffix = true): string|WP_Error
    {
        $info = pathinfo($fileName);
        $name = $info['filename'] ?? 'upload';
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
        $name = $this->limitFileBaseNameLength($name, ltrim($extension, '.'));
        $targetPath = $directory . DIRECTORY_SEPARATOR . $name . $extension;

        if (!$allowUniqueSuffix) {
            if (file_exists($targetPath)) {
                return new WP_Error('file_exists', 'Eine Datei mit diesem Namen existiert bereits.');
            }

            return $targetPath;
        }

        $counter = 1;

        while (file_exists($targetPath)) {
            $suffix = '-' . $counter;
            $candidateName = $this->limitFileBaseNameLength($name, ltrim($extension, '.'), $suffix) . $suffix;
            $targetPath = $directory . DIRECTORY_SEPARATOR . $candidateName . $extension;
            $counter++;
        }

        return $targetPath;
    }

    private function limitFileBaseNameLength(string $name, string $extension = '', string $suffix = ''): string
    {
        $extensionLength = $extension !== '' ? min(strlen($extension), self::MAX_EXTENSION_LENGTH) + 1 : 0;
        $maxBaseLength = max(1, self::MAX_STORED_FILENAME_LENGTH - $extensionLength - strlen($suffix));

        if (strlen($name) <= $maxBaseLength) {
            return $name;
        }

        return substr($name, 0, $maxBaseLength);
    }

    private function resolveManagedUploadPath(string $path, ?array $settings = null): string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        if (empty($settings['organize_month_year'])) {
            return $normalized;
        }

        if (preg_match('#(?:^|/)\d{4}/\d{2}/\d{2}$#', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('#(?:^|/)\d{4}/\d{2}$#', $normalized) === 1) {
            return trim($normalized . '/' . date('d'), '/');
        }

        if (preg_match('#(?:^|/)\d{4}$#', $normalized) === 1) {
            return trim($normalized . '/' . date('m') . '/' . date('d'), '/');
        }

        $datePath = date('Y') . '/' . date('m') . '/' . date('d');
        return trim(($normalized !== '' ? $normalized . '/' : '') . $datePath, '/');
    }

    private function deleteDirectory(string $directory): bool
    {
        $items = scandir($directory);
        if ($items === false) {
            $this->logFilesystemFailure('scandir', 'Verzeichnis konnte nicht gelesen werden', [
                'path' => $directory,
            ]);
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_link($itemPath)) {
                if (!$this->deleteFile($itemPath)) {
                    return false;
                }

                continue;
            }

            if (is_dir($itemPath)) {
                if (!$this->deleteDirectory($itemPath)) {
                    return false;
                }
            } elseif (!$this->deleteFile($itemPath)) {
                return false;
            }
        }

        if (!is_writable(dirname($directory))) {
            $this->logFilesystemFailure('rmdir_preflight', 'Übergeordnetes Verzeichnis ist nicht beschreibbar', [
                'path' => $directory,
            ]);
            return false;
        }

        if (rmdir($directory)) {
            return true;
        }

        $this->logFilesystemFailure('rmdir', 'Verzeichnis konnte nicht gelöscht werden', [
            'path' => $directory,
        ]);

        return false;
    }

    private function isConvertibleImage(string $filePath): bool
    {
        $mimeType = mime_content_type($filePath) ?: '';
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif'], true);
    }

    private function resolveUploadFileMode(string $relativePath): int
    {
        $normalizedPath = trim(str_replace('\\', '/', $relativePath), '/');

        if ($normalizedPath === '' || str_starts_with($normalizedPath, 'member/') || $normalizedPath === 'member') {
            return self::PRIVATE_FILE_MODE;
        }

        foreach (explode('/', $normalizedPath) as $segment) {
            if ($segment !== '' && str_starts_with($segment, '.')) {
                return self::PRIVATE_FILE_MODE;
            }
        }

        return self::PUBLIC_FILE_MODE;
    }

    private function ensureDirectoryExists(string $directory, string $errorCode, string $message): true|WP_Error
    {
        if (is_dir($directory)) {
            if (!is_writable($directory)) {
                return new WP_Error($errorCode, 'Zielverzeichnis ist nicht beschreibbar');
            }

            return true;
        }

        $parentDir = dirname($directory);
        if ($parentDir === $directory || $parentDir === '') {
            return new WP_Error($errorCode, $message);
        }

        if (!is_dir($parentDir)) {
            $parentReady = $this->ensureDirectoryExists($parentDir, $errorCode, $message);
            if ($parentReady instanceof WP_Error) {
                return $parentReady;
            }
        }

        if (!is_writable($parentDir)) {
            return new WP_Error($errorCode, 'Übergeordnetes Verzeichnis ist nicht beschreibbar');
        }

        if (mkdir($directory, 0755) || is_dir($directory)) {
            return true;
        }

        $this->logFilesystemFailure('mkdir', $message, [
            'path' => $directory,
        ]);

        return new WP_Error($errorCode, $message);
    }

    private function moveUploadedFileToTarget(string $sourcePath, string $targetPath): bool
    {
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            return false;
        }

        if (is_uploaded_file($sourcePath)) {
            return move_uploaded_file($sourcePath, $targetPath);
        }

        if (PHP_SAPI !== 'cli') {
            return false;
        }

        if (!is_writable(dirname($sourcePath))) {
            return false;
        }

        if (@rename($sourcePath, $targetPath)) {
            return true;
        }

        if (!@copy($sourcePath, $targetPath)) {
            return false;
        }

        if (!@unlink($sourcePath)) {
            @unlink($targetPath);
            return false;
        }

        return true;
    }

    private function deleteFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return true;
        }

        if (!is_writable($filePath) && !is_writable(dirname($filePath))) {
            $this->logFilesystemFailure('unlink_preflight', 'Datei oder Verzeichnis ist nicht beschreibbar', [
                'path' => $filePath,
            ]);
            return false;
        }

        if (unlink($filePath)) {
            return true;
        }

        $this->logFilesystemFailure('unlink', 'Datei konnte nicht gelöscht werden', [
            'path' => $filePath,
        ]);

        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFilesystemFailure(string $operation, string $message, array $context = []): void
    {
        $this->logger->warning($message, array_merge(['operation' => $operation], $context));
    }

    private function normalizeRenameTargetName(string $existingPath, string $newName): string|WP_Error
    {
        $newName = trim($newName);
        if ($newName === '' || $newName === '.' || $newName === '..' || str_starts_with($newName, '.') || preg_match('#[\\\\/:*?"<>|\x00-\x1F]#', $newName)) {
            return new WP_Error('invalid_name', 'Ungültiger Name');
        }

        $newName = trim($newName, " \t\n\r\0\x0B.");
        if ($newName === '') {
            return new WP_Error('invalid_name', 'Ungültiger Name');
        }

        if (is_dir($existingPath)) {
            return $newName;
        }

        $currentExtension = strtolower((string) pathinfo($existingPath, PATHINFO_EXTENSION));
        $requestedExtension = strtolower((string) pathinfo($newName, PATHINFO_EXTENSION));
        $requestedBaseName = $requestedExtension !== ''
            ? (string) pathinfo($newName, PATHINFO_FILENAME)
            : $newName;
        $requestedBaseName = trim($requestedBaseName, " \t\n\r\0\x0B.");
        $requestedBaseName = str_replace('.', '-', $requestedBaseName);
        $requestedBaseName = preg_replace('/[^A-Za-z0-9_-]+/u', '-', $requestedBaseName) ?: $requestedBaseName;
        $requestedBaseName = preg_replace('/[-_]{2,}/', '-', $requestedBaseName) ?: $requestedBaseName;
        $requestedBaseName = trim($requestedBaseName, " \t\n\r\0\x0B.-_");

        if ($requestedBaseName === '') {
            return new WP_Error('invalid_name', 'Ungültiger Name');
        }

        if ($currentExtension !== '' && $requestedExtension !== '' && $requestedExtension !== $currentExtension) {
            return new WP_Error('extension_change_forbidden', 'Die Dateiendung kann beim Umbenennen nicht geändert werden');
        }

        if ($currentExtension === '' && $requestedExtension !== '') {
            return new WP_Error('extension_change_forbidden', 'Dateien ohne Endung dürfen nicht in einen anderen Dateityp umbenannt werden');
        }

        $requestedBaseName = $this->limitFileBaseNameLength($requestedBaseName, $currentExtension);

        return $requestedBaseName . ($currentExtension !== '' ? '.' . $currentExtension : '');
    }
}
