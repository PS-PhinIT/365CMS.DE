<?php
/**
 * Papierkorb-Provider, deren Einträge über ihre ID in den Papierkorb verschoben werden (Beiträge, Seiten,
 * Hub-Sites). Medien werden pfadbasiert verschoben (`MediaTrashProvider::trashPath()`).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\Trash;

use CMS\Services\ContentTypes\TrashProvider;
use CMS\Services\ContentTypes\TrashResult;

if (!defined('ABSPATH')) {
    exit;
}

interface TrashableProvider extends TrashProvider
{
    public function trash(int $id, int $userId): TrashResult;

    public function isTrashed(int $id): bool;
}
