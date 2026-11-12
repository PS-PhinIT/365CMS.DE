<?php

declare(strict_types=1);

namespace CMS\Services\Knowledgebase\Support;

use CMS\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/** Logger der Knowledgebase (Kanal `knowledgebase`). */
final class LoggerFactory
{
    public static function create(): Logger
    {
        return Logger::instance()->withChannel('knowledgebase');
    }
}
