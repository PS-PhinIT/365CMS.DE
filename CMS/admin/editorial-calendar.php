<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redaktionskalender – Entry Point
 * Route: /admin/editorial-calendar
 *
 * Monats- und Wochenansicht geplanter Inhalte. Termine und Verschieben laufen über den JSON-Endpunkt
 * /admin/editorial-calendar-api; die Seite selbst nimmt keine Formulare entgegen.
 */

use CMS\Auth;

const CMS_ADMIN_EDITORIAL_CALENDAR_READ_CAPABILITY = 'view_editorial_calendar';

function cms_admin_editorial_calendar_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_EDITORIAL_CALENDAR_READ_CAPABILITY);
}

$sectionPageConfig = [
    'section' => 'editorial-calendar',
    'route_path' => '/admin/editorial-calendar',
    'view_file' => __DIR__ . '/views/content/editorial-calendar.php',
    'page_title' => __('calendar.page_title'),
    'active_page' => 'editorial-calendar',
    'page_assets' => [
        'css' => [
            cms_asset_url('css/admin-editorial-calendar.css'),
        ],
        'js' => [
            cms_asset_url('js/admin-editorial-calendar.js'),
        ],
    ],
    'csrf_action' => 'admin_editorial_calendar',
    'module_file' => __DIR__ . '/modules/content/EditorialCalendarModule.php',
    'module_factory' => static fn (): EditorialCalendarModule => new EditorialCalendarModule(),
    'data_loader' => static fn (EditorialCalendarModule $module): array => $module->getData($_GET),
    'access_checker' => static fn (): bool => cms_admin_editorial_calendar_can_access(),
    'unknown_action_message' => __('calendar.error.unknown_action'),
    'post_handler' => static fn (): array => ['success' => false, 'error' => __('calendar.error.unknown_action')],
];

require __DIR__ . '/partials/section-page-shell.php';
