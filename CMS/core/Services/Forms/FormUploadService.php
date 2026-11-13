<?php
/**
 * Datei-Uploads aus Formularen: Inhaltsprüfung (finfo), Ablage im privaten Speicher
 * (`private/forms/uploads/<zufallsname>`), Abruf nur über den Admin-Bereich mit Rechteprüfung.
 *
 * Erlaubte Endungen und Höchstgröße setzen die globalen Formular-Einstellungen (Obergrenze) und das Feld.
 * Ausführbare bzw. aktive Inhalte (PHP, HTML, SVG, JavaScript, Programme) sind immer gesperrt.
 *
 * @package CMSv2\Core\Services\Forms
 */

declare(strict_types=1);

namespace CMS\Services\Forms;

use CMS\Database;
use CMS\Services\PrivateStorageService;

if (!defined('ABSPATH')) {
    exit;
}

final class FormUploadService
{
    public const AREA = 'forms/uploads';

    /** Erwartete MIME-Typen je Endung (finfo-Ergebnis). Unbekannte Endungen: nur Sperrliste. */
    private const MIME_MAP = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg', 'image/pjpeg'],
        'jpeg' => ['image/jpeg', 'image/pjpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'avif' => ['image/avif'],
        'heic' => ['image/heic', 'image/heif'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
        'rtf' => ['text/rtf', 'application/rtf'],
        'doc' => ['application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'mp3' => ['audio/mpeg'],
        'mp4' => ['video/mp4'],
    ];

    private const DANGEROUS_MIME = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'application/javascript', 'text/javascript',
        'application/x-php', 'text/x-php', 'application/x-httpd-php', 'application/x-msdownload',
        'application/x-dosexec', 'application/x-executable', 'application/x-sharedlib', 'application/x-sh',
        'text/x-shellscript', 'application/java-archive', 'application/x-mach-binary', 'text/xml', 'application/xml',
    ];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Wandelt `$_FILES['fields']` in eine Liste je Feldname um (einzeln oder mehrfach).
     *
     * @return array<string, list<array{name: string, type: string, tmp_name: string, error: int, size: int}>>
     */
    public function collect(mixed $raw): array
    {
        if (!is_array($raw) || !isset($raw['name']) || !is_array($raw['name'])) {
            return [];
        }

        $result = [];
        foreach ($raw['name'] as $field => $names) {
            $field = (string) $field;
            $isList = is_array($names);
            $names = $isList ? $names : [$names];
            foreach (array_keys($names) as $index) {
                $pick = static function (string $key) use ($raw, $field, $isList, $index): mixed {
                    $value = $raw[$key][$field] ?? null;

                    return $isList ? (is_array($value) ? ($value[$index] ?? null) : null) : $value;
                };
                if (is_array($pick('name'))) {
                    continue;
                }
                $result[$field][] = [
                    'name' => (string) $pick('name'),
                    'type' => (string) $pick('type'),
                    'tmp_name' => (string) $pick('tmp_name'),
                    'error' => (int) ($pick('error') ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($pick('size') ?? 0),
                ];
            }
        }

        return $result;
    }

    /**
     * Prüft den tatsächlichen Dateiinhalt aller Datei-Felder. Liefert Fehler je Feldname.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @param array{upload_max_kb?: int, upload_types?: list<string>} $limits
     * @return array<string, string>
     */
    public function checkContents(array $fields, array $values, string $locale, array $limits, bool $requireUploaded = true): array
    {
        $errors = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }
            foreach (is_array($values[$field['name']] ?? null) ? $values[$field['name']] : [] as $file) {
                $tmp = (string) ($file['tmp_name'] ?? '');
                $extension = self::extension((string) ($file['name'] ?? ''));
                if ($tmp === '' || ($requireUploaded && !is_uploaded_file($tmp)) || !is_file($tmp) || !$this->contentMatches($tmp, $extension)) {
                    $allowed = FormValidator::allowedExtensions(is_array($field['validation'] ?? null) ? $field['validation'] : [], $limits);
                    $errors[(string) $field['name']] = sprintf(FormI18n::t('forms.error.file_type', $locale), implode(', ', $allowed));
                    break;
                }
            }
        }

        return $errors;
    }

    public function contentMatches(string $path, string $extension): bool
    {
        if (in_array($extension, FormSettings::BLOCKED_UPLOAD_TYPES, true)) {
            return false;
        }
        $mime = self::detectMime($path);
        if ($mime === '' || in_array($mime, self::DANGEROUS_MIME, true)) {
            return false;
        }
        if (isset(self::MIME_MAP[$extension])) {
            return in_array($mime, self::MIME_MAP[$extension], true);
        }

        $head = (string) @file_get_contents($path, false, null, 0, 1024);

        return preg_match('/<\?php|<script|<html|<svg/i', $head) !== 1;
    }

    /**
     * Anzahl der geprüften Dateien über alle Datei-Felder (Soll für `storeAll()`).
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     */
    public static function countFiles(array $fields, array $values): int
    {
        $count = 0;
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }
            $count += count(is_array($values[$field['name'] ?? ''] ?? null) ? $values[$field['name']] : []);
        }

        return $count;
    }

    /**
     * Legt alle Dateien eines Eingangs ab und speichert die Zuordnung.
     *
     * @param list<array<string, mixed>> $fields
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    public function storeAll(int $submissionId, array $fields, array $values, bool $requireUploaded = true): array
    {
        $stored = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'file') {
                continue;
            }
            foreach (is_array($values[$field['name']] ?? null) ? $values[$field['name']] : [] as $file) {
                $row = $this->store($submissionId, (string) $field['name'], $file, $requireUploaded);
                if ($row !== null) {
                    $stored[] = $row;
                }
            }
        }

        return $stored;
    }

    /**
     * @param array<string, mixed> $file Eintrag aus `collect()`
     * @return array<string, mixed>|null
     */
    public function store(int $submissionId, string $fieldName, array $file, bool $requireUploaded = true): ?array
    {
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($requireUploaded && !is_uploaded_file($tmp))) {
            return null;
        }

        $original = self::cleanFilename((string) ($file['name'] ?? 'datei'));
        $extension = self::extension($original);
        try {
            $storage = PrivateStorageService::getInstance();
            $directory = $storage->path(self::AREA);
            $storedName = $storage->randomFilename($extension);
        } catch (\Throwable $e) {
            error_log('FormUploadService: privater Speicher nicht verfügbar – ' . $e->getMessage());

            return null;
        }

        $target = $directory . $storedName;
        $moved = $requireUploaded ? @move_uploaded_file($tmp, $target) : @copy($tmp, $target);
        if (!$moved) {
            return null;
        }
        @chmod($target, 0640);

        $row = [
            'submission_id' => $submissionId,
            'field_name' => substr($fieldName, 0, 64),
            'original_name' => $original,
            'stored_name' => $storedName,
            'mime_type' => substr(self::detectMime($target), 0, 127),
            'size_bytes' => (int) (filesize($target) ?: 0),
        ];
        $id = Database::instance()->insert('form_submission_files', $row);
        if (!$id) {
            @unlink($target);

            return null;
        }

        return ['id' => (int) $id] + $row;
    }

    /**
     * Absoluter Pfad einer gespeicherten Datei oder null.
     *
     * @param array<string, mixed> $file
     */
    public function path(array $file): ?string
    {
        $stored = (string) ($file['stored_name'] ?? '');
        if (preg_match('/^[a-f0-9]{32}(?:\.[a-z0-9]{1,10})?$/', $stored) !== 1) {
            return null;
        }
        try {
            return PrivateStorageService::getInstance()->resolve(self::AREA, $stored);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $file
     */
    public function deleteStoredFile(array $file): void
    {
        $path = $this->path($file);
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Liefert eine Datei aus (immer als Download, ohne Ausführung im Browser).
     *
     * @param array<string, mixed> $file
     */
    public function stream(array $file): never
    {
        $path = $this->path($file);
        if ($path === null || !is_readable($path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Not found';
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $name = self::cleanFilename((string) ($file['original_name'] ?? 'datei'));
        $ascii = (string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
        $mime = (string) ($file['mime_type'] ?? '');
        if ($mime === '' || in_array($mime, self::DANGEROUS_MIME, true) || preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime) !== 1) {
            $mime = 'application/octet-stream';
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($path);
        exit;
    }

    public static function cleanFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>:|?*\/]+/u', '_', $name), " .\t");
        if ($name === '') {
            $name = 'datei';
        }

        return function_exists('mb_substr') ? mb_substr($name, 0, 200, 'UTF-8') : substr($name, 0, 200);
    }

    public static function extension(string $name): string
    {
        return strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    }

    private static function detectMime(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($path);

            return is_string($mime) ? strtolower($mime) : '';
        }
        if (function_exists('mime_content_type')) {
            return strtolower((string) mime_content_type($path));
        }

        return 'application/octet-stream';
    }
}
