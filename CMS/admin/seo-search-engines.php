<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$seoPageConfig = [
    'section' => 'search_engines',
    'route_path' => '/admin/seo-search-engines',
    'view_file' => __DIR__ . '/views/seo/search-engines.php',
    'page_title' => 'Suchmaschinen',
    'active_page' => 'seo-search-engines',
];

require __DIR__ . '/seo-page.php';
