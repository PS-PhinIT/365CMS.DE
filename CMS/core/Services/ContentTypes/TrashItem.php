<?php
/**
 * Eintrag im Papierkorb, wie ihn ein `TrashProvider` für die gemeinsame Übersicht liefert.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class TrashItem
{
    /**
     * @param string               $trashedAt Zeitpunkt `Y-m-d H:i:s` (leer, falls unbekannt)
     * @param array<string, mixed> $meta      typspezifische Zusatzangaben (z. B. `mime`, `locale`, `slug`)
     */
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $title,
        public readonly string $trashedAt = '',
        public readonly ?int $trashedBy = null,
        public readonly string $previousStatus = '',
        public readonly string $editUrl = '',
        public readonly array $meta = [],
    ) {
    }
}
