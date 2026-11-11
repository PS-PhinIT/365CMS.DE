<?php
/**
 * Vorschau eines einzelnen Import-Datensatzes (ohne Schreibzugriff).
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class ImportPreview
{
    public const ACTION_CREATE = 'create';
    public const ACTION_CONFLICT = 'conflict';
    public const ACTION_INVALID = 'invalid';

    /**
     * @param string $action      `create`, `conflict` oder `invalid`
     * @param string $label       Anzeigename des Datensatzes (z. B. Titel)
     * @param string $conflictKey Schlüssel, an dem der Konflikt hängt (z. B. Slug), sonst leer
     * @param string $message     Hinweis für die Oberfläche
     */
    public function __construct(
        public readonly string $action,
        public readonly string $label,
        public readonly string $conflictKey = '',
        public readonly string $message = '',
    ) {
    }
}
