<?php
/**
 * Export/Import von Mediendateien (Typ `media`).
 *
 * Export: verwaltete Originaldateien unter `uploads/` (ohne Systemordner und ohne erzeugte Vorschaubilder/WebP) mit
 * Alt-Text, Titel, Bildunterschrift und Lizenzangaben; die Datei selbst nur mit „Mediendateien einschließen“
 * (Archivpfad `files/media/<pfad>`). Datensatz-ID ist der relative Pfad.
 *
 * Import: Konflikt = Datei mit gleichem Pfad vorhanden. `skip` behält die vorhandene Datei, `overwrite` ersetzt sie,
 * `rename` legt `name-2.ext` … an. Der neue Pfad steht im Namensraum `media_path`, damit Beiträge und Seiten ihre
 * Verweise umschreiben. Erlaubt sind nur Dateiendungen, die auch der Upload erlaubt; Vorschaubilder werden neu erzeugt.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Exchange;

use CMS\Database;
use CMS\Services\ContentTypes\ExchangeFilter;
use CMS\Services\ContentTypes\ExchangeProvider;
use CMS\Services\ContentTypes\ExportBatch;
use CMS\Services\ContentTypes\ImportContext;
use CMS\Services\ContentTypes\ImportPreview;
use CMS\Services\ContentTypes\ImportResult;
use CMS\Services\Media\MediaLicenseService;
use CMS\Services\MediaService;

if (!defined('ABSPATH')) {
    exit;
}

final class MediaExchangeProvider implements ExchangeProvider
{
    public const TYPE = 'media';
    private const DERIVATIVE_PATTERN = '/-(small|medium|large|banner)\.[a-z0-9]+$/i';
    private const BLOCKED_EXTENSIONS = ['php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'pht', 'cgi', 'pl', 'py', 'sh', 'exe', 'htaccess', 'html', 'htm', 'svg', 'js'];

    /** @var list<array<string, mixed>>|null */
    private ?array $inventory = null;

    public function type(): string
    {
        return self::TYPE;
    }

    public function label(): string
    {
        return (string) __('exchange.type.media');
    }

    public function capability(): string
    {
        return 'manage_media';
    }

    public function importPriority(): int
    {
        return 20;
    }

    public function countExport(ExchangeFilter $filter): int
    {
        return count($this->inventory());
    }

    public function exportBatch(ExchangeFilter $filter, int $cursor, int $limit): ExportBatch
    {
        $limit = max(1, min(200, $limit));
        $items = array_slice($this->inventory(), $cursor, $limit);
        $records = [];
        $files = [];
        $root = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR;
        $meta = $this->mediaRows(array_map(static fn (array $item): string => (string) $item['path'], $items));
        foreach ($items as $item) {
            $path = (string) $item['path'];
            $absolute = $root . $path;
            $row = $meta[$path] ?? [];
            $record = [
                'id' => $path,
                'path' => $path,
                'size' => (int) $item['size'],
                'sha256' => is_file($absolute) ? (string) hash_file('sha256', $absolute) : '',
                'title' => (string) ($row['title'] ?? ''),
                'alt_text' => (string) ($row['alt_text'] ?? ''),
                'caption' => (string) ($row['caption'] ?? ''),
                'category' => (string) ($item['category'] ?? ''),
                'tags' => (array) ($item['tags'] ?? []),
                'license' => $this->license($path),
                'has_file' => $filter->includeMediaFiles,
            ];
            if ($filter->includeMediaFiles && is_file($absolute)) {
                $files['files/media/' . $path] = $absolute;
            }
            $records[] = $record;
        }
        $next = $cursor + count($items);

        return new ExportBatch($records, $files, $next, $next >= count($this->inventory()));
    }

    public function preview(array $record, ImportContext $context): ImportPreview
    {
        $path = self::cleanPath((string) ($record['path'] ?? ''));
        if ($path === '' || !self::allowedExtension($path)) {
            return new ImportPreview(ImportPreview::ACTION_INVALID, $path !== '' ? $path : '?', '', (string) __('exchange.error.media_type'));
        }
        if (!empty($record['has_file']) && $context->stagedFile('files/media/' . $path) === null) {
            return new ImportPreview(ImportPreview::ACTION_INVALID, $path, '', (string) __('exchange.error.media_file_missing'));
        }

        return is_file(rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $path)
            ? new ImportPreview(ImportPreview::ACTION_CONFLICT, $path, $path, (string) __('exchange.preview.file_exists'))
            : new ImportPreview(empty($record['has_file']) ? ImportPreview::ACTION_INVALID : ImportPreview::ACTION_CREATE, $path, '', empty($record['has_file']) ? (string) __('exchange.error.media_file_missing') : '');
    }

    public function import(array $record, ImportContext $context): ImportResult
    {
        $preview = $this->preview($record, $context);
        $path = self::cleanPath((string) ($record['path'] ?? ''));
        $root = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR;
        if ($preview->action === ImportPreview::ACTION_INVALID) {
            return new ImportResult(ImportResult::FAILED, null, $preview->message);
        }

        $status = ImportResult::CREATED;
        $target = $path;
        if ($preview->action === ImportPreview::ACTION_CONFLICT) {
            if ($context->conflictStrategy === ImportContext::STRATEGY_SKIP || empty($record['has_file'])) {
                $context->map('media_path', $path, $path);

                return new ImportResult(ImportResult::SKIPPED, null, $preview->message);
            }
            if ($context->conflictStrategy === ImportContext::STRATEGY_RENAME) {
                $target = $this->freePath($path);
                $status = ImportResult::RENAMED;
            } else {
                $status = ImportResult::UPDATED;
            }
        }

        $source = $context->stagedFile('files/media/' . $path);
        if ($source === null) {
            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.media_file_missing'));
        }
        $expected = (string) ($record['sha256'] ?? '');
        if ($expected !== '' && !hash_equals($expected, (string) hash_file('sha256', $source))) {
            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.checksum'));
        }

        $absolute = $root . $target;
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.save'));
        }
        $realRoot = realpath($root);
        $realDirectory = realpath($directory);
        if ($realRoot === false || $realDirectory === false || !str_starts_with($realDirectory . DIRECTORY_SEPARATOR, rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.media_type'));
        }
        if (!copy($source, $absolute)) {
            return new ImportResult(ImportResult::FAILED, null, (string) __('exchange.error.save'));
        }

        $this->afterImport($target, $record, $context->userId);
        $context->map('media_path', $path, $target);

        return new ImportResult($status, null, $status === ImportResult::RENAMED ? sprintf((string) __('exchange.result.renamed'), $target) : '');
    }

    // ── Intern ──────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function inventory(): array
    {
        if ($this->inventory !== null) {
            return $this->inventory;
        }
        $items = [];
        try {
            foreach ((new MediaService())->collectManagedFileInventory(10000) as $item) {
                $path = (string) ($item['path'] ?? '');
                if ($path === '' || !empty($item['is_system']) || preg_match(self::DERIVATIVE_PATTERN, $path) === 1 || !self::allowedExtension($path)) {
                    continue;
                }
                // WebP-Begleitdatei eines Rasterbilds (`bild.webp` neben `bild.jpg`) wird neu erzeugt.
                if (str_ends_with(strtolower($path), '.webp')) {
                    $base = substr($path, 0, -5);
                    foreach (['.jpg', '.jpeg', '.png', '.gif'] as $extension) {
                        if (is_file(rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $base . $extension)) {
                            continue 2;
                        }
                    }
                }
                $items[] = $item;
            }
        } catch (\Throwable) {
            $items = [];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        return $this->inventory = $items;
    }

    /**
     * @param list<string> $paths
     * @return array<string, array<string, mixed>>
     */
    private function mediaRows(array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $db = Database::instance();
        $rows = [];
        try {
            $candidates = [];
            foreach ($paths as $path) {
                $candidates[] = $path;
                $candidates[] = '/uploads/' . $path;
                $candidates[] = 'uploads/' . $path;
            }
            $result = $db->execute(
                'SELECT filepath, title, alt_text, caption FROM ' . $db->getPrefix() . 'media WHERE filepath IN (' . implode(',', array_fill(0, count($candidates), '?')) . ')',
                $candidates
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($result as $row) {
                $rows[ltrim(preg_replace('#^/?uploads/#', '', (string) $row['filepath']) ?? '', '/')] = $row;
            }
        } catch (\Throwable) {
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function license(string $path): array
    {
        try {
            return MediaLicenseService::getInstance()->get($path);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $record */
    private function afterImport(string $path, array $record, int $userId): void
    {
        $db = Database::instance();
        try {
            $existing = $db->get_var('SELECT id FROM ' . $db->getPrefix() . 'media WHERE filepath = ? LIMIT 1', [$path]);
            $values = [
                'title' => ExchangeSupport::text($record['title'] ?? '', 255),
                'alt_text' => ExchangeSupport::text($record['alt_text'] ?? '', 500),
                'caption' => ExchangeSupport::text($record['caption'] ?? '', 2000, true),
            ];
            if ($existing !== null) {
                $db->update('media', $values, ['id' => (int) $existing]);
            } elseif ($values['title'] !== '' || $values['alt_text'] !== '' || $values['caption'] !== '') {
                $absolute = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR . $path;
                $db->insert('media', $values + [
                    'filename' => basename($path),
                    'filepath' => $path,
                    'filetype' => (string) (mime_content_type($absolute) ?: 'application/octet-stream'),
                    'filesize' => (int) (filesize($absolute) ?: 0),
                    'uploaded_by' => $userId > 0 ? $userId : null,
                ]);
            }
        } catch (\Throwable) {
        }

        $license = is_array($record['license'] ?? null) ? array_filter($record['license'], static fn ($v): bool => $v !== '' && $v !== null && $v !== false) : [];
        if ($license !== []) {
            try {
                MediaLicenseService::getInstance()->save($path, $record['license']);
            } catch (\Throwable) {
            }
        }

        try {
            $service = new MediaService();
            if ((string) ($record['category'] ?? '') !== '') {
                $service->assignCategory($path, (string) $record['category']);
            }
            $tags = array_values(array_filter(array_map('strval', (array) ($record['tags'] ?? []))));
            if ($tags !== []) {
                $service->assignTags($path, $tags);
            }
            $service->processImageDerivativeJobItem($path, true, true);
        } catch (\Throwable) {
        }
    }

    private function freePath(string $path): string
    {
        $root = rtrim((string) UPLOAD_PATH, '/\\') . DIRECTORY_SEPARATOR;
        $info = pathinfo($path);
        $directory = ($info['dirname'] ?? '.') !== '.' ? $info['dirname'] . '/' : '';
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';
        for ($n = 2; $n < 1000; $n++) {
            $candidate = $directory . $info['filename'] . '-' . $n . $extension;
            if (!file_exists($root . $candidate)) {
                return $candidate;
            }
        }

        return $directory . $info['filename'] . '-' . bin2hex(random_bytes(3)) . $extension;
    }

    public static function cleanPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path) === 1) {
            return '';
        }

        return preg_match('#^[A-Za-z0-9._\-/ ]{1,400}$#u', $path) === 1 ? $path : '';
    }

    private static function allowedExtension(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && !in_array($extension, self::BLOCKED_EXTENSIONS, true) && !str_starts_with(basename($path), '.');
    }
}
