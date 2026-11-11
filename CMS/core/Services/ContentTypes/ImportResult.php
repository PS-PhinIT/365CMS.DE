<?php
/**
 * Ergebnis des Imports eines einzelnen Datensatzes.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class ImportResult
{
    public const CREATED = 'created';
    public const UPDATED = 'updated';
    public const RENAMED = 'renamed';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?int $newId = null,
        public readonly string $message = '',
    ) {
    }

    public function succeeded(): bool
    {
        return in_array($this->status, [self::CREATED, self::UPDATED, self::RENAMED], true);
    }
}
