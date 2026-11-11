<?php
/**
 * Ein Export-Stapel eines `ExchangeProvider`.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class ExportBatch
{
    /**
     * @param list<array<string, mixed>> $records    JSON-serialisierbare Datensätze, je mit `id`
     * @param array<string, string>      $files      relativer Archivpfad unter `files/` => absoluter Quellpfad
     * @param int                        $nextCursor Cursor für den nächsten Stapel
     * @param bool                       $done       true, wenn keine weiteren Datensätze folgen
     */
    public function __construct(
        public readonly array $records,
        public readonly array $files,
        public readonly int $nextCursor,
        public readonly bool $done,
    ) {
    }
}
