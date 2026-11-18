<?php
/**
 * Eine Seite einer Keyset-Liste: `$next` ist der Cursor der folgenden Seite, null = letzte Seite.
 *
 * @package CMSv2\Core\Services\Community
 */

declare(strict_types=1);

namespace CMS\Services\Community\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class Page
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(public readonly array $items, public readonly ?string $next)
    {
    }
}
