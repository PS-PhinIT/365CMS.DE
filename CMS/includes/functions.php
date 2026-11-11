<?php
declare(strict_types=1);
/**
 * Helper Functions
 *
 * Global utility bootstrap for themed helper groups.
 *
 * @package CMSv2\Includes
 */

if (!defined('ABSPATH')) {
    exit;
}

if (defined('CMS_GLOBAL_FUNCTIONS_LOADED') || function_exists('esc_html')) {
    return;
}

define('CMS_GLOBAL_FUNCTIONS_LOADED', true);

$cmsFunctionFiles = [
    __DIR__ . '/functions/translation.php',
    __DIR__ . '/functions/escaping.php',
    __DIR__ . '/functions/options-runtime.php',
    __DIR__ . '/functions/redirects-auth.php',
    __DIR__ . '/functions/roles.php',
    __DIR__ . '/functions/admin-menu.php',
    __DIR__ . '/functions/admin-menu-profiles.php',
    __DIR__ . '/functions/wordpress-compat.php',
    __DIR__ . '/functions/mail.php',
    __DIR__ . '/functions/plugin-public-i18n.php',
];

foreach ($cmsFunctionFiles as $cmsFunctionFile) {
    if (is_file($cmsFunctionFile)) {
        require_once $cmsFunctionFile;
    }
}

unset($cmsFunctionFile, $cmsFunctionFiles);
