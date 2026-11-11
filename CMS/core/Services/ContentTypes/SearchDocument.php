<?php
/**
 * Ein indexierbares Dokument, wie es ein `SearchDocumentProvider` liefert.
 *
 * @package CMSv2\Core\Services
 */

declare(strict_types=1);

namespace CMS\Services\ContentTypes;

if (!defined('ABSPATH')) {
    exit;
}

final class SearchDocument
{
    /**
     * @param string $content   reiner Text (ohne HTML/Editor.js-JSON)
     * @param string $url       absoluter oder wurzelrelativer Link auf die öffentliche Seite
     * @param string $updatedAt Zeitpunkt `Y-m-d H:i:s` der letzten inhaltlichen Änderung
     */
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $content,
        public readonly string $url,
        public readonly string $excerpt = '',
        public readonly string $updatedAt = '',
    ) {
    }
}
