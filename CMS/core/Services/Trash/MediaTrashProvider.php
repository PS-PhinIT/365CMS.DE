<?php
/**
 * Papierkorb-Provider für Mediendateien.
 *
 * Ablage: Beim Verschieben in den Papierkorb wandern die Datei und ihre erzeugten Derivate
 * (`name-{small,medium,large,banner}.ext`, `name.webp`) in das geschützte private Verzeichnis
 * `trash/media/<storage_name>/` (PrivateStorageService, per `.htaccess` gesperrt). Die Tabelle
 * `trash_media` hält Originalpfad, Dateiliste, Metadaten aus `config/media-meta.json` und die Zeilen
 * der Tabelle `media` (Alt-Texte) als JSON. Wiederherstellen legt alles an den Originalpfad zurück;
 * ist der Pfad inzwischen belegt, erhält die Datei samt Derivaten das Suffix `-2`, `-3` …
 *
 * Ordner werden dateiweise in den Papierkorb gelegt (jede Datei ein eigener Eintrag mit Originalpfad,
 * Wiederherstellen legt den Ordner bei Bedarf neu an); danach wird der leere Ordner entfernt.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

use CMS\AuditLogger;
use CMS\Database;
use CMS\Json;
use CMS\Logger;
use CMS\Services\ContentTypes\TrashItem;
use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;
use CMS\Services\Media\MediaRepository;
use CMS\Services\MediaUsageService;
use CMS\Services\PrivateStorageService;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaTrashProvider implements TrashProvider
{
    public const TYPE = 'media';

    public const STORAGE_AREA = 'trash/media';

    public const MAX_FOLDER_FILES = 500;

    private const SYSTEM_FOLDERS = ['themes', 'plugins', 'assets', 'fonts', 'dl-manager', 'form-uploads', 'member'];

    private const THUMBNAIL_SUFFIXES = ['small', 'medium', 'large', 'banner'];

    private const THUMBNAIL_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const WEBP_SOURCE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif'];

    private const MAX_RESTORE_ATTEMPTS = 500;

    private readonly Database $db;
    private readonly string $prefix;
    private readonly string $uploadRoot;
    private ?MediaRepository $repository = null;
    private ?bool $trashTableExists = null;
    private ?bool $mediaTableExists = null;

    public function __construct(?Database $db = null, ?string $uploadRoot = null)
    {
        $this->db = $db ?? Database::instance();
        $this->prefix = $this->db->getPrefix();
        $root = $uploadRoot ?? (defined('UPLOAD_PATH') ? (string) UPLOAD_PATH : ABSPATH . 'uploads');
        $this->uploadRoot = rtrim($root, '/\\');
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return TrashSupport::t('trash.type.media');
    }

    public function capability(): string
    {
        return 'manage_media';
    }

    public function countTrashed(string $search = ''): int
    {
        if (!$this->hasTrashTable()) {
            return 0;
        }

        [$where, $params] = $this->trashWhere($search);

        return (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->prefix}trash_media t WHERE {$where}", $params);
    }

    public function listTrashed(int $offset, int $limit, string $search = ''): array
    {
        if (!$this->hasTrashTable()) {
            return [];
        }

        [$where, $params] = $this->trashWhere($search);
        $params[] = max(1, min(500, $limit));
        $params[] = max(0, $offset);

        $rows = $this->db->get_results(
            "SELECT t.id, t.original_path, t.title, t.filetype, t.filesize, t.payload_json, t.trashed_at, t.trashed_by,
                    u.display_name AS trashed_by_name, u.username AS trashed_by_username
             FROM {$this->prefix}trash_media t
             LEFT JOIN {$this->prefix}users u ON u.id = t.trashed_by
             WHERE {$where}
             ORDER BY t.trashed_at DESC, t.id DESC
             LIMIT ? OFFSET ?",
            $params
        ) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $payload = Json::decodeArray($row->payload_json ?? null, []);
            $files = is_array($payload['files'] ?? null) ? $payload['files'] : [];
            $trashedByName = trim((string) ($row->trashed_by_name ?? ''));
            if ($trashedByName === '') {
                $trashedByName = trim((string) ($row->trashed_by_username ?? ''));
            }
            $title = trim((string) ($row->title ?? ''));
            $originalPath = (string) ($row->original_path ?? '');

            $items[] = new TrashItem(
                self::TYPE,
                (int) ($row->id ?? 0),
                $title !== '' ? $title : basename($originalPath),
                TrashSupport::timestamp($row->trashed_at ?? null),
                ($row->trashed_by ?? null) !== null ? (int) $row->trashed_by : null,
                '',
                '',
                [
                    'original_path' => $originalPath,
                    'filetype' => (string) ($row->filetype ?? ''),
                    'filesize' => (int) ($row->filesize ?? 0),
                    'file_count' => count($files),
                    'trashed_by_name' => $trashedByName,
                ]
            );
        }

        return $items;
    }

    /**
     * Verschiebt eine Datei oder einen Ordner (relativ zum Upload-Verzeichnis) in den Papierkorb.
     */
    public function trashPath(string $relativePath, int $userId): TrashResult
    {
        $path = self::normalizeRelativePath($relativePath);
        if ($path === '') {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.media.error.invalid_path'));
        }

        if ($this->isSystemPath($path)) {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.media.error.protected'));
        }

        $absolute = $this->absolutePath($path);
        if ($absolute === null || !file_exists($absolute)) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        return is_dir($absolute) && !is_link($absolute)
            ? $this->trashFolder($path, $absolute, $userId)
            : $this->trashFile($path, $userId);
    }

    /**
     * Verschiebt eine einzelne Datei samt erzeugter Derivate in den Papierkorb.
     */
    public function trashFile(string $relativePath, int $userId, bool $audit = true): TrashResult
    {
        $path = self::normalizeRelativePath($relativePath);
        $absolute = $path !== '' ? $this->absolutePath($path) : null;
        if ($absolute === null || !is_file($absolute)) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        if ($this->isSystemPath($path)) {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.media.error.protected'));
        }

        if (!$this->hasTrashTable()) {
            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.unavailable'));
        }

        $directory = dirname($absolute);
        $filename = basename($path);
        [$base, $suffix] = self::splitFilename($filename);

        $files = [['name' => $filename, 'suffix' => $suffix, 'role' => 'original']];
        foreach (self::companionSuffixes($filename) as $companionSuffix) {
            $companionName = $base . $companionSuffix;
            $companionPath = $directory . DIRECTORY_SEPARATOR . $companionName;
            if ($companionName !== $filename && is_file($companionPath) && !is_link($companionPath)) {
                $files[] = ['name' => $companionName, 'suffix' => $companionSuffix, 'role' => 'derivative'];
            }
        }

        $mime = function_exists('mime_content_type') ? (string) (@mime_content_type($absolute) ?: '') : '';
        $originalSize = (int) (@filesize($absolute) ?: 0);
        $storageName = bin2hex(random_bytes(16));

        try {
            $storageDir = PrivateStorageService::getInstance()->path(self::STORAGE_AREA . '/' . $storageName);
        } catch (\Throwable $e) {
            return $this->error('trash', 0, $e, ['path' => $path]);
        }

        $moved = [];
        foreach ($files as $index => $file) {
            $stored = $index . '-' . $file['name'];
            $source = $directory . DIRECTORY_SEPARATOR . $file['name'];
            $size = (int) (@filesize($source) ?: 0);
            if (!self::moveFile($source, $storageDir . $stored)) {
                $this->rollbackMoves($moved, $directory, $storageDir);
                self::removeDirectory($storageDir);
                Logger::instance()->withChannel('trash')->error('Mediendatei konnte nicht in den Papierkorb verschoben werden.', [
                    'path' => $path,
                    'file' => $file['name'],
                ]);

                return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.move_failed', $file['name']));
            }
            $moved[] = $file + ['stored' => $stored, 'size' => $size];
        }

        $meta = $this->repository()->getFileMeta($path);
        $mediaRows = $this->mediaRowsFor($path);
        $payload = [
            'version' => 1,
            'files' => $moved,
            'meta' => $meta,
            'media_rows' => $mediaRows,
            'mime' => $mime,
        ];

        try {
            $id = $this->db->insert('trash_media', [
                'original_path' => $path,
                'storage_name' => $storageName,
                'title' => self::truncate($filename, 255),
                'filetype' => self::truncate($mime !== '' ? $mime : strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), 100),
                'filesize' => $originalSize,
                'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                'trashed_at' => date('Y-m-d H:i:s'),
                'trashed_by' => $userId > 0 ? $userId : null,
            ]);
        } catch (\Throwable $e) {
            $id = false;
            Logger::instance()->withChannel('trash')->error('Papierkorb-Eintrag für Mediendatei konnte nicht angelegt werden.', [
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);
        }

        if ($id === false || (int) $id <= 0) {
            $this->rollbackMoves($moved, $directory, $storageDir);
            self::removeDirectory($storageDir);

            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.error.failed'));
        }

        $id = (int) $id;
        $relativeDirectory = self::parentPath($path);
        foreach ($moved as $file) {
            $this->repository()->setFileMeta(self::joinPath($relativeDirectory, (string) $file['name']), null);
            $this->deleteMediaRows(self::joinPath($relativeDirectory, (string) $file['name']));
        }

        TrashSupport::clearContentCache($this->db, 'media_trash', $id);

        if ($audit) {
            $this->audit('trash', sprintf('Mediendatei „%s“ in den Papierkorb verschoben.', $path), $id, [
                'path' => $path,
                'files' => array_column($moved, 'name'),
                'user_id' => $userId,
            ]);
        }

        return TrashResult::success(TrashSupport::t('trash.msg.trashed', $filename), [
            'id' => $id,
            'path' => $path,
            'file_count' => count($moved),
        ]);
    }

    public function restore(int $id, int $userId): TrashResult
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        $originalPath = self::normalizeRelativePath($row['original_path']);
        $files = $row['files'];
        if ($originalPath === '' || $files === []) {
            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.storage_missing'));
        }

        $storageDir = $this->storageDirectory($row['storage_name']);
        if ($storageDir === null) {
            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.storage_missing'));
        }

        foreach ($files as $file) {
            if (!is_file($storageDir . $file['stored'])) {
                return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.storage_missing'));
            }
        }

        $relativeDirectory = self::parentPath($originalPath);
        $targetDirectory = $this->uploadRoot . ($relativeDirectory !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory) : '');
        if (file_exists($targetDirectory) && !is_dir($targetDirectory)) {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.media.error.target_blocked', $relativeDirectory));
        }

        if (!is_dir($targetDirectory) && !@mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.target_blocked', $relativeDirectory));
        }

        if ($this->absolutePath($relativeDirectory === '' ? '' : $relativeDirectory, true) === null) {
            return TrashResult::failure(TrashResult::CODE_INVALID_STATE, TrashSupport::t('trash.media.error.invalid_path'));
        }

        [$originalBase, $originalSuffix] = self::splitFilename(basename($originalPath));
        $suffixes = array_map(static fn (array $file): string => $file['suffix'], $files);
        $targetBase = self::resolveRestoreBase(
            $originalBase,
            $suffixes,
            static fn (string $name): bool => file_exists($targetDirectory . DIRECTORY_SEPARATOR . $name)
        );

        $restored = [];
        foreach ($files as $file) {
            $target = $targetDirectory . DIRECTORY_SEPARATOR . $targetBase . $file['suffix'];
            if (!self::moveFile($storageDir . $file['stored'], $target)) {
                foreach ($restored as [$storedName, $restoredTarget]) {
                    self::moveFile($restoredTarget, $storageDir . $storedName);
                }

                Logger::instance()->withChannel('trash')->error('Mediendatei konnte nicht aus dem Papierkorb wiederhergestellt werden.', [
                    'id' => $id,
                    'file' => $file['stored'],
                ]);

                return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.move_failed', $file['name']));
            }
            $restored[] = [$file['stored'], $target];
        }

        $targetPath = self::joinPath($relativeDirectory, $targetBase . $originalSuffix);
        if ($row['meta'] !== []) {
            $this->repository()->setFileMeta($targetPath, $row['meta']);
        }
        $this->restoreMediaRows($row['media_rows'], $targetPath);

        try {
            $this->db->execute("DELETE FROM {$this->prefix}trash_media WHERE id = ? LIMIT 1", [$id]);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Papierkorb-Eintrag einer wiederhergestellten Mediendatei konnte nicht entfernt werden.', [
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);
        }
        self::removeDirectory(rtrim($storageDir, '/\\'));

        $pathChanged = $targetPath !== $originalPath;
        TrashSupport::clearContentCache($this->db, 'media_restore', $id);
        $this->audit('restore', sprintf('Mediendatei „%s“ aus dem Papierkorb wiederhergestellt.', $targetPath), $id, [
            'original_path' => $originalPath,
            'path' => $targetPath,
            'path_changed' => $pathChanged,
            'user_id' => $userId,
        ]);

        $message = TrashSupport::t('trash.msg.restored', basename($targetPath));
        if ($pathChanged) {
            $message .= ' ' . TrashSupport::t('trash.media.msg.path_changed', $originalPath, $targetPath);
        }

        return TrashResult::success($message, [
            'id' => $id,
            'path' => $targetPath,
            'original_path' => $originalPath,
            'path_changed' => $pathChanged,
        ]);
    }

    public function deletionWarnings(int $id): array
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return [];
        }

        $path = self::normalizeRelativePath($row['original_path']);
        if ($path === '') {
            return [];
        }

        try {
            $usages = MediaUsageService::getInstance()->buildUsageMap([$path])[$path] ?? [];
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Medien-Verwendungen konnten nicht geprüft werden.', [
                'id' => $id,
                'exception' => $e->getMessage(),
            ]);

            return [TrashSupport::t('trash.media.warning.usage_unknown')];
        }

        $warnings = [];
        foreach ($usages as $usage) {
            if (!is_array($usage)) {
                continue;
            }

            $warnings[] = TrashSupport::t(
                'trash.media.warning.used_in',
                (string) ($usage['content_type_label'] ?? ''),
                (string) ($usage['title'] ?? ''),
                (string) ($usage['field_label'] ?? '')
            );
        }

        return $warnings;
    }

    public function deletePermanently(int $id, int $userId, bool $confirmed = false): TrashResult
    {
        $row = $this->fetchRow($id);
        if ($row === null) {
            return TrashResult::failure(TrashResult::CODE_NOT_FOUND, TrashSupport::t('trash.error.not_found'));
        }

        $title = $row['title'] !== '' ? $row['title'] : basename($row['original_path']);
        $warnings = $this->deletionWarnings($id);
        if ($warnings !== [] && !$confirmed) {
            return TrashSupport::confirmationRequired($warnings, $title);
        }

        $storageDir = $this->storageDirectory($row['storage_name']);
        if ($storageDir !== null && !self::removeDirectory(rtrim($storageDir, '/\\'))) {
            Logger::instance()->withChannel('trash')->error('Papierkorb-Ablage einer Mediendatei konnte nicht gelöscht werden.', [
                'id' => $id,
            ]);

            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.error.failed'));
        }

        try {
            $this->db->execute("DELETE FROM {$this->prefix}trash_media WHERE id = ? LIMIT 1", [$id]);
        } catch (\Throwable $e) {
            return $this->error('delete', $id, $e);
        }

        $this->audit('delete_permanently', sprintf('Mediendatei „%s“ endgültig gelöscht.', $row['original_path']), $id, [
            'path' => $row['original_path'],
            'files' => array_column($row['files'], 'name'),
            'usage_warnings' => count($warnings),
            'user_id' => $userId,
        ], $userId > 0 ? 'warning' : 'info');

        return TrashResult::success(TrashSupport::t('trash.msg.deleted', $title), ['id' => $id]);
    }

    public function expiredIds(\DateTimeImmutable $trashedBefore, int $limit): array
    {
        if (!$this->hasTrashTable()) {
            return [];
        }

        $ids = $this->db->get_col(
            "SELECT id FROM {$this->prefix}trash_media WHERE trashed_at < ? ORDER BY trashed_at ASC, id ASC LIMIT ?",
            [$trashedBefore->format('Y-m-d H:i:s'), max(1, $limit)]
        );

        return array_values(array_map('intval', $ids));
    }

    /**
     * Pfad relativ zum Upload-Verzeichnis normalisieren (identisch zur Medienverwaltung); leer bei ungültigem Pfad.
     */
    public static function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?? '';
        $path = trim($path, '/');

        if ($path === '' || str_contains($path, '..') || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return '';
        }

        return preg_match('#^[A-Za-z0-9._\-/]+$#', $path) === 1 ? $path : '';
    }

    /**
     * Zerlegt einen Dateinamen in Basis und Endungs-Suffix: `foto.jpg` → [`foto`, `.jpg`].
     *
     * @return array{0:string,1:string}
     */
    public static function splitFilename(string $filename): array
    {
        $position = strrpos($filename, '.');
        if ($position === false || $position === 0) {
            return [$filename, ''];
        }

        return [substr($filename, 0, $position), substr($filename, $position)];
    }

    /**
     * Suffixe (relativ zur Basis) der vom Medienmodul erzeugten Derivate einer Datei.
     *
     * @return list<string>
     */
    public static function companionSuffixes(string $filename): array
    {
        [, $suffix] = self::splitFilename($filename);
        $extension = strtolower(ltrim($suffix, '.'));
        $suffixes = [];

        if (in_array($extension, self::THUMBNAIL_EXTENSIONS, true)) {
            foreach (self::THUMBNAIL_SUFFIXES as $size) {
                $suffixes[] = '-' . $size . $suffix;
            }
        }

        if (in_array($extension, self::WEBP_SOURCE_EXTENSIONS, true)) {
            $suffixes[] = '.webp';
        }

        return $suffixes;
    }

    /**
     * Freie Basis für die Wiederherstellung: Original, sonst `-2`, `-3` … – für Datei und alle Derivate frei.
     *
     * @param list<string> $suffixes
     * @param callable(string): bool $exists
     */
    public static function resolveRestoreBase(string $base, array $suffixes, callable $exists): string
    {
        $isFree = static function (string $candidate) use ($suffixes, $exists): bool {
            foreach ($suffixes as $suffix) {
                if ($exists($candidate . $suffix)) {
                    return false;
                }
            }

            return true;
        };

        if ($isFree($base)) {
            return $base;
        }

        for ($counter = 2; $counter <= self::MAX_RESTORE_ATTEMPTS; $counter++) {
            if ($isFree($base . '-' . $counter)) {
                return $base . '-' . $counter;
            }
        }

        do {
            $candidate = $base . '-' . bin2hex(random_bytes(3));
        } while (!$isFree($candidate));

        return $candidate;
    }

    private function trashFolder(string $path, string $absolute, int $userId): TrashResult
    {
        if (!$this->hasTrashTable()) {
            return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.media.error.unavailable'));
        }

        $files = [];
        if (!$this->collectFolderFiles($absolute, $path, $files)) {
            return TrashResult::failure(
                TrashResult::CODE_INVALID_STATE,
                TrashSupport::t('trash.media.error.folder_too_large', self::MAX_FOLDER_FILES)
            );
        }

        $companions = [];
        foreach ($files as $file) {
            $directory = self::parentPath($file);
            [$base] = self::splitFilename(basename($file));
            foreach (self::companionSuffixes(basename($file)) as $suffix) {
                $companion = self::joinPath($directory, $base . $suffix);
                if ($companion !== $file) {
                    $companions[$companion] = true;
                }
            }
        }

        $trashed = 0;
        $errors = [];
        $ordered = array_merge(
            array_values(array_filter($files, static fn (string $file): bool => !isset($companions[$file]))),
            array_values(array_filter($files, static fn (string $file): bool => isset($companions[$file])))
        );
        foreach ($ordered as $file) {
            $fileAbsolute = $this->absolutePath($file);
            if ($fileAbsolute === null || !is_file($fileAbsolute)) {
                continue;
            }

            $result = $this->trashFile($file, $userId, false);
            if ($result->ok) {
                $trashed += (int) ($result->data['file_count'] ?? 1);
                continue;
            }
            $errors[] = $file . ': ' . $result->message;
        }

        $folderRemoved = $errors === [] && self::removeDirectory($absolute, true);
        if ($folderRemoved) {
            $this->repository()->purgeMetaForPath($path);
            $this->deleteMediaRows($path, true);
        }

        TrashSupport::clearContentCache($this->db, 'media_trash_folder', 0);
        $this->audit('trash_folder', sprintf('Medienordner „%s“ in den Papierkorb verschoben (%d Dateien).', $path, $trashed), null, [
            'path' => $path,
            'files' => $trashed,
            'errors' => array_slice($errors, 0, 20),
            'folder_removed' => $folderRemoved,
            'user_id' => $userId,
        ], $errors === [] ? 'info' : 'warning');

        if ($errors !== []) {
            return TrashResult::failure(
                TrashResult::CODE_ERROR,
                TrashSupport::t('trash.media.error.folder_partial', $trashed, count($errors)),
                ['errors' => $errors, 'file_count' => $trashed]
            );
        }

        return TrashResult::success(TrashSupport::t('trash.media.msg.folder_trashed', $path, $trashed), [
            'path' => $path,
            'file_count' => $trashed,
        ]);
    }

    /**
     * Sammelt sichtbare Dateien eines Ordners rekursiv; false, wenn die Obergrenze überschritten wird.
     *
     * @param list<string> $files
     */
    private function collectFolderFiles(string $absolute, string $relative, array &$files): bool
    {
        $entries = @scandir($absolute);
        if ($entries === false) {
            return true;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $entryAbsolute = $absolute . DIRECTORY_SEPARATOR . $entry;
            $entryRelative = $relative . '/' . $entry;
            if (is_dir($entryAbsolute) && !is_link($entryAbsolute)) {
                if (!$this->collectFolderFiles($entryAbsolute, $entryRelative, $files)) {
                    return false;
                }
                continue;
            }

            $files[] = $entryRelative;
            if (count($files) > self::MAX_FOLDER_FILES) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array{name:string,suffix:string,role:string,stored:string,size:int}> $moved
     */
    private function rollbackMoves(array $moved, string $directory, string $storageDir): void
    {
        foreach ($moved as $file) {
            self::moveFile($storageDir . $file['stored'], $directory . DIRECTORY_SEPARATOR . $file['name']);
        }
    }

    /**
     * @return array{id:int,original_path:string,storage_name:string,title:string,files:list<array{name:string,suffix:string,role:string,stored:string,size:int}>,meta:array<string,mixed>,media_rows:list<array<string,mixed>>}|null
     */
    private function fetchRow(int $id): ?array
    {
        if ($id <= 0 || !$this->hasTrashTable()) {
            return null;
        }

        $row = $this->db->get_row(
            "SELECT id, original_path, storage_name, title, payload_json FROM {$this->prefix}trash_media WHERE id = ? LIMIT 1",
            [$id]
        );
        if ($row === null) {
            return null;
        }

        $payload = Json::decodeArray($row->payload_json ?? null, []);
        $files = [];
        foreach ((array) ($payload['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }

            $name = (string) ($file['name'] ?? '');
            $stored = (string) ($file['stored'] ?? '');
            $suffix = (string) ($file['suffix'] ?? '');
            if (preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1 || preg_match('/^\d+-[A-Za-z0-9._-]+$/', $stored) !== 1
                || ($suffix !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $suffix) !== 1)) {
                continue;
            }

            $files[] = [
                'name' => $name,
                'suffix' => $suffix,
                'role' => (string) ($file['role'] ?? 'derivative'),
                'stored' => $stored,
                'size' => (int) ($file['size'] ?? 0),
            ];
        }

        $mediaRows = [];
        foreach ((array) ($payload['media_rows'] ?? []) as $mediaRow) {
            if (is_array($mediaRow)) {
                $mediaRows[] = $mediaRow;
            }
        }

        return [
            'id' => (int) $row->id,
            'original_path' => (string) ($row->original_path ?? ''),
            'storage_name' => (string) ($row->storage_name ?? ''),
            'title' => trim((string) ($row->title ?? '')),
            'files' => $files,
            'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            'media_rows' => $mediaRows,
        ];
    }

    private function storageDirectory(string $storageName): ?string
    {
        if (preg_match('/^[a-f0-9]{32}$/', $storageName) !== 1) {
            return null;
        }

        try {
            $resolved = PrivateStorageService::getInstance()->resolve(self::STORAGE_AREA, $storageName);
        } catch (\Throwable) {
            return null;
        }

        return $resolved !== null && is_dir($resolved) ? rtrim($resolved, '/\\') . DIRECTORY_SEPARATOR : null;
    }

    /**
     * @return array{0:string,1:list<mixed>}
     */
    private function trashWhere(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return ['1 = 1', []];
        }

        $like = TrashSupport::likePattern($search);

        return ['(t.original_path LIKE ? OR t.title LIKE ?)', [$like, $like]];
    }

    /**
     * Absoluter Pfad innerhalb des Upload-Verzeichnisses; null bei Pfaden außerhalb (Symlinks, Traversal).
     */
    private function absolutePath(string $relativePath, bool $allowRoot = false): ?string
    {
        $root = realpath($this->uploadRoot);
        if ($root === false) {
            return null;
        }

        if ($relativePath === '') {
            return $allowRoot ? $root : null;
        }

        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $existing = file_exists($candidate) ? realpath(is_link($candidate) ? dirname($candidate) : $candidate) : realpath(dirname($candidate));
        if ($existing === false || ($existing !== $root && !str_starts_with($existing, $root . DIRECTORY_SEPARATOR))) {
            return null;
        }

        return $candidate;
    }

    private function isSystemPath(string $relativePath): bool
    {
        $parts = array_values(array_filter(explode('/', trim($relativePath, '/')), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return true;
        }

        if (count($parts) === 1 && in_array($parts[0], self::SYSTEM_FOLDERS, true)) {
            return true;
        }

        return count($parts) === 2 && $parts[0] === 'member';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mediaRowsFor(string $relativePath): array
    {
        if (!$this->hasMediaTable()) {
            return [];
        }

        try {
            $rows = $this->db->get_results("SELECT * FROM {$this->prefix}media WHERE filepath = ?", [$relativePath]) ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_map(static fn (object $row): array => get_object_vars($row), $rows));
    }

    private function deleteMediaRows(string $relativePath, bool $includeChildren = false): void
    {
        if (!$this->hasMediaTable()) {
            return;
        }

        try {
            if ($includeChildren) {
                $this->db->execute(
                    "DELETE FROM {$this->prefix}media WHERE filepath = ? OR filepath LIKE ?",
                    [$relativePath, TrashSupport::likePattern($relativePath . '/', false)]
                );

                return;
            }

            $this->db->execute("DELETE FROM {$this->prefix}media WHERE filepath = ?", [$relativePath]);
        } catch (\Throwable $e) {
            Logger::instance()->withChannel('trash')->warning('Medien-Datensätze konnten nicht entfernt werden.', [
                'path' => $relativePath,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function restoreMediaRows(array $rows, string $targetPath): void
    {
        if ($rows === [] || !$this->hasMediaTable()) {
            return;
        }

        $allowed = ['filename', 'filetype', 'filesize', 'title', 'alt_text', 'caption', 'uploaded_by', 'uploaded_at'];
        foreach ($rows as $row) {
            $data = array_intersect_key($row, array_flip($allowed));
            $data['filepath'] = $targetPath;
            $data['filename'] = basename($targetPath);

            try {
                $this->db->insert('media', $data);
            } catch (\Throwable $e) {
                Logger::instance()->withChannel('trash')->warning('Medien-Datensatz konnte nicht wiederhergestellt werden.', [
                    'path' => $targetPath,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    private function repository(): MediaRepository
    {
        return $this->repository ??= new MediaRepository(
            $this->uploadRoot,
            rtrim(defined('UPLOAD_URL') ? (string) UPLOAD_URL : '/uploads', '/\\'),
            ABSPATH . 'config/media-meta.json',
            self::SYSTEM_FOLDERS,
            Logger::instance()->withChannel('media.trash')
        );
    }

    /** Ist der Medien-Papierkorb einsatzbereit (Tabelle `trash_media` vorhanden)? */
    public function isAvailable(): bool
    {
        return $this->hasTrashTable();
    }

    private function hasTrashTable(): bool
    {
        return $this->trashTableExists ??= $this->db->tableExists($this->prefix . 'trash_media');
    }

    private function hasMediaTable(): bool
    {
        return $this->mediaTableExists ??= $this->db->tableExists($this->prefix . 'media');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function audit(string $action, string $description, ?int $id, array $metadata, string $severity = 'info'): void
    {
        AuditLogger::instance()->log(AuditLogger::CAT_MEDIA, 'media.' . $action, $description, 'media', $id, $metadata, $severity);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(string $action, int $id, \Throwable $e, array $context = []): TrashResult
    {
        Logger::instance()->withChannel('trash')->error('Papierkorb-Aktion für Mediendatei fehlgeschlagen.', $context + [
            'action' => $action,
            'id' => $id,
            'exception_class' => get_debug_type($e),
            'exception' => $e->getMessage(),
        ]);

        return TrashResult::failure(TrashResult::CODE_ERROR, TrashSupport::t('trash.error.failed'));
    }

    /**
     * Verschiebt eine Datei; über Dateisystemgrenzen (Upload- und Privatspeicher auf verschiedenen
     * Mounts) per Kopieren, Größenvergleich und Löschen der Quelle.
     */
    private static function moveFile(string $source, string $target): bool
    {
        if (!is_file($source) || file_exists($target)) {
            return false;
        }

        if (@rename($source, $target)) {
            return true;
        }

        clearstatcache(true, $source);
        $size = @filesize($source);
        if (!@copy($source, $target)) {
            return false;
        }

        clearstatcache(true, $target);
        if ($size === false || @filesize($target) !== $size || !@unlink($source)) {
            @unlink($target);

            return false;
        }

        return true;
    }

    /**
     * Entfernt ein Verzeichnis rekursiv. Mit `$onlyIfNoVisibleFiles` bricht es ab, sobald noch sichtbare
     * Dateien vorhanden sind (versteckte Systemdateien wie `.htaccess` werden mitgelöscht).
     */
    private static function removeDirectory(string $directory, bool $onlyIfNoVisibleFiles = false): bool
    {
        $directory = rtrim($directory, '/\\');
        if ($directory === '' || !is_dir($directory) || is_link($directory)) {
            return !file_exists($directory);
        }

        if ($onlyIfNoVisibleFiles && self::containsVisibleFiles($directory)) {
            return false;
        }

        $entries = @scandir($directory);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                if (!self::removeDirectory($path)) {
                    return false;
                }
                continue;
            }

            if (!@unlink($path)) {
                return false;
            }
        }

        return @rmdir($directory);
    }

    private static function containsVisibleFiles(string $directory): bool
    {
        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                if (self::containsVisibleFiles($path)) {
                    return true;
                }
                continue;
            }

            if (!str_starts_with($entry, '.')) {
                return true;
            }
        }

        return false;
    }

    private static function parentPath(string $relativePath): string
    {
        $parent = str_replace('\\', '/', dirname($relativePath));

        return $parent === '.' || $parent === '/' ? '' : trim($parent, '/');
    }

    private static function joinPath(string $directory, string $name): string
    {
        return $directory !== '' ? $directory . '/' . $name : $name;
    }

    private static function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }
}
