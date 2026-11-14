<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;

const CMS_ADMIN_LOGINPAGE_ROUTE_PATH = '/admin/cms-loginpage';
const CMS_ADMIN_LOGINPAGE_CAPABILITY = 'manage_settings';

function cms_admin_loginpage_can_access(): bool
{
    return Auth::instance()->isAdmin() && Auth::instance()->hasCapability(CMS_ADMIN_LOGINPAGE_CAPABILITY);
}

$sectionPageConfig = [
    'route_path' => CMS_ADMIN_LOGINPAGE_ROUTE_PATH,
    'view_file' => __DIR__ . '/views/themes/cms-loginpage.php',
    'page_title' => 'CMS Loginpage',
    'active_page' => 'cms-loginpage',
    'page_assets' => [
        // Medien-Picker für das Showcase-Bild (Auswahl aus der Mediathek, kein Upload im Tab).
        'js' => [cms_asset_url('js/admin-media-integrations.js')],
    ],
    'csrf_action' => 'admin_cms_loginpage',
    'module_file' => __DIR__ . '/modules/themes/CmsLoginPageModule.php',
    'module_factory' => static fn (): CmsLoginPageModule => new CmsLoginPageModule(),
    'data_loader' => static fn (CmsLoginPageModule $module): array => $module->getData(),
    'access_checker' => static fn (): bool => cms_admin_loginpage_can_access(),
    'access_denied_route' => '/',
    'post_handler' => static function (CmsLoginPageModule $module, string $section, array $post): array {
        return $module->handlePost($post);
    },
];

require __DIR__ . '/partials/section-page-shell.php';
