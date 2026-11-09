<?php
declare(strict_types=1);

/**
 * Admin Partial: HTML <head> + CSS
 *
 * Erwartet vor dem include:
 *   $pageTitle   – string  Seitentitel (z. B. "Dashboard")
 *   $pageAssets  – array   Optionale zusätzliche Assets: 'css', 'js' (klassisch, defer) und
 *                           'modules' (ES-Module, werden im Footer als type="module" geladen)
 *
 * Setzt auf <html> den Site-Kontext (data-site) und den gespeicherten Zustand der
 * Seitenleiste (data-sidebar). Die ES-Module der Admin-Shell werden über eine
 * Importmap (cms-admin/<datei>.js → versionierte Asset-URL) aufgelöst.
 *
 * @package CMSv2\Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

$pageTitle  = $pageTitle ?? 'Admin';
$pageAssets = $pageAssets ?? [];
$siteUrl    = defined('SITE_URL') ? SITE_URL : '';
$siteName   = function_exists('cms_get_site_name') ? cms_get_site_name() : (defined('SITE_NAME') ? SITE_NAME : '365CMS');

$adminShellSiteKey = '';
$adminShellAccentCss = '';
$adminShellCollapsed = false;
try {
    $adminShellSiteContext = \CMS\Services\AdminSiteContextService::getInstance();
    $adminShellCurrentSite = $adminShellSiteContext->getCurrentSite();
    $adminShellSiteKey = (string) ($adminShellCurrentSite['key'] ?? '');

    if ($adminShellSiteKey !== '' && !$adminShellSiteContext->hasStaticAccent($adminShellSiteKey)) {
        $adminShellAccent = (string) $adminShellCurrentSite['accent'];
        $adminShellAccentRgb = implode(', ', array_map('hexdec', str_split(substr($adminShellAccent, 1), 2)));
        $adminShellAccentCss = 'html:root{--cms-accent:' . $adminShellAccent
            . ';--cms-accent-rgb:' . $adminShellAccentRgb
            . ';--cms-accent-soft:' . (string) $adminShellCurrentSite['accent_soft']
            . ';--cms-accent-strong:' . (string) $adminShellCurrentSite['accent_strong'] . '}';
    }

    foreach ($adminShellSiteContext->getSites() as $adminShellSiteOption) {
        $adminShellAccentCss .= '.cms-site-swatch[data-site-swatch="' . $adminShellSiteOption['key'] . '"]{background:' . $adminShellSiteOption['accent'] . '}';
    }

    $adminShellUserId = (int) (\CMS\Auth::getCurrentUser()->id ?? 0);
    $adminShellCollapsed = $adminShellUserId > 0
        && \CMS\Services\AdminUiPreferenceService::getInstance()->isSidebarCollapsed($adminShellUserId);
} catch (\Throwable) {
    $adminShellAccentCss = '';
}

// Importmap für die Admin-ES-Module: bare specifier → versionierte Asset-URL (Cache-Busting)
$adminShellImports = [];
foreach (['cms-admin/' => 'js/admin-shell', 'cms-editor/' => 'js/post-editor'] as $adminShellModulePrefix => $adminShellModuleDir) {
    foreach (glob(cms_assets_path($adminShellModuleDir) . DIRECTORY_SEPARATOR . '*.js') ?: [] as $adminShellModuleFile) {
        $adminShellModuleName = basename($adminShellModuleFile);
        if (preg_match('/^[a-z0-9-]+\.js$/', $adminShellModuleName) === 1) {
            $adminShellImports[$adminShellModulePrefix . $adminShellModuleName] = cms_asset_url($adminShellModuleDir . '/' . $adminShellModuleName);
        }
    }
}

\CMS\CacheManager::instance()->sendResponseHeaders('private');
?>
<!doctype html>
<html lang="de" data-site="<?= htmlspecialchars($adminShellSiteKey, ENT_QUOTES, 'UTF-8') ?>" data-sidebar="<?= $adminShellCollapsed ? 'collapsed' : 'expanded' ?>">
<head>
    <meta charset="utf-8">
    <?= function_exists('cms_csp_runtime_tags') ? cms_csp_runtime_tags() : '' ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($pageTitle) ?> – <?= htmlspecialchars($siteName) ?> Admin</title>
    <?php $adminIconUrl = htmlspecialchars(cms_asset_url('images/365CMS-DASHBOARD-Admin-100px.png', false), ENT_QUOTES); ?>
    <link rel="icon" href="<?= $adminIconUrl ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?= $adminIconUrl ?>">
    <link rel="preload" href="<?= htmlspecialchars(cms_asset_url('fonts/inter/inter-latin-wght-normal.woff2', false), ENT_QUOTES) ?>" as="font" type="font/woff2" crossorigin>

    <!-- Tabler Core CSS -->
    <link rel="stylesheet" href="<?= htmlspecialchars(cms_asset_url('tabler/css/tabler.min.css'), ENT_QUOTES) ?>">
    <!-- Local Tabler Icons webfont (CDN/runtime external assets are forbidden) -->
    <link rel="stylesheet" href="<?= htmlspecialchars(cms_asset_url('tabler-icons/tabler-icons.min.css'), ENT_QUOTES) ?>">

    <!-- 365CMS Tabler Bridge / Legacy-Mappings -->
    <link rel="stylesheet" href="<?= htmlspecialchars(cms_asset_url('css/admin-tabler.css'), ENT_QUOTES) ?>">

    <!-- 365CMS Admin Overrides -->
    <link rel="stylesheet" href="<?= htmlspecialchars(cms_asset_url('css/admin.css'), ENT_QUOTES) ?>">

    <!-- 365CMS Admin-Shell: Layout, Seitenleiste, Topbar, Drawer (Tokens: admin-tabler.css) -->
    <link rel="stylesheet" href="<?= htmlspecialchars(cms_asset_url('css/admin-shell.css'), ENT_QUOTES) ?>">

    <?php if ($adminShellAccentCss !== ''): ?>
    <style <?= \CMS\Security::instance()->nonceAttr() ?>><?= $adminShellAccentCss ?></style>
    <?php endif; ?>

    <?php
    // Zusätzliche Stylesheets aus $pageAssets['css']
    if (!empty($pageAssets['css'])):
        foreach ($pageAssets['css'] as $css): ?>
            <link rel="stylesheet" href="<?= htmlspecialchars($css) ?>">
        <?php endforeach;
    endif;
    ?>

    <?php if ($adminShellImports !== []): ?>
    <script type="importmap" <?= \CMS\Security::instance()->nonceAttr() ?>><?= json_encode(['imports' => $adminShellImports], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>

    <?php \CMS\Hooks::doAction('admin_head'); ?>
</head>
<body class="layout-fluid admin-body">
    <a class="cms-skip-link" href="#cms-main">Zum Inhalt springen</a>
    <div class="page">
