<?php
/**
 * Speicher des Verwendungsindex (Tabelle `media_usage`) – austauschbar für `TESTS/media-usage`.
 *
 * @package CMSv2\Core\Services\Media
 */

declare(strict_types=1);

namespace CMS\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

interface MediaUsageStoreInterface
{
    /**
     * Ersetzt alle Zeilen eines Inhalts.
     *
     * @param list<array{path:string, context:string}> $references
     */
    public function replace(string $contentType, int $contentId, array $references, string $now): void;

    public function delete(string $contentType, int $contentId): void;

    /**
     * @param list<string> $paths
     * @return list<array{media_path:string, content_type:string, content_id:int, context:string}>
     */
    public function rowsForPaths(array $paths): array;

    /**
     * Zeilen, die vor `$before` zuletzt geschrieben wurden (Inhalte, die es beim Neuaufbau nicht mehr gab), entfernen.
     */
    public function pruneBefore(string $before): int;
}
