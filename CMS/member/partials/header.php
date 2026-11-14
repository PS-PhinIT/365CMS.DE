<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;
use CMS\Member\MemberSanitizer;

if (!defined('ABSPATH')) {
    exit;
}

$controller = $controller ?? \CMS\MemberArea\MemberController::instance();
$settings = $settings ?? $controller->getSettings();
$t ??= static fn (string $key, array $params = []): string => MemberI18n::t($key, $params);
$e ??= static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$pageTitle = (string) ($pageTitle ?? $t('member.common.area'));
$pageKey = (string) ($pageKey ?? 'dashboard');
$pageAssets = $pageAssets ?? [];
$siteName = function_exists('cms_get_site_name') ? cms_get_site_name() : (defined('SITE_NAME') ? (string) SITE_NAME : '365CMS');
$htmlLang = MemberI18n::locale();
$design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
$memberPrimary = MemberSanitizer::color($design['primary'] ?? '#6366f1', '#6366f1');
$memberAccent = MemberSanitizer::color($design['accent'] ?? '#8b5cf6', '#8b5cf6');
$memberBg = MemberSanitizer::color($design['bg'] ?? '#f1f5f9', '#f1f5f9');
$memberCardBg = MemberSanitizer::color($design['card_bg'] ?? '#ffffff', '#ffffff');
$memberText = MemberSanitizer::color($design['text'] ?? '#1e293b', '#1e293b');
$memberBorder = MemberSanitizer::color($design['border'] ?? '#e2e8f0', '#e2e8f0');
$bodyClass = 'member-shell member-page-' . preg_replace('/[^a-z0-9\-]+/i', '-', $pageKey);
$displayName = $controller->getDisplayName();
$avatar = $controller->getAvatarUrl();
$memberMenu = $controller->getMenuItems($pageKey);
$homeHref = $controller->getHomePath();
$profileHref = '/member/profile';
$securityHref = '/member/security';
$notificationsHref = '/member/notifications';
$logoutHref = '/logout?csrf_token=' . rawurlencode(\CMS\Security::instance()->generateToken('logout'));
$unreadNotifications = $controller->getUnreadNotificationCount();
$showProfileGateNotice = $pageKey !== 'profile' && $controller->isProfileCompletionRequired();
$dashboardLogo = MemberSanitizer::asset($settings['dashboard_logo'] ?? '');
if ($dashboardLogo === '') {
    $dashboardLogo = cms_asset_url('images/365CMS-DASHBOARD-Member-100px.png', false);
}
\CMS\Hooks::addFilter('site_favicon_default', static fn(): string => 'images/365CMS-DASHBOARD-Member-100px.png');

// Dynamische Werte als CSS-Variablen im Nonce-Block statt style-Attributen (UI-16):
// Seiten setzen vor dem Einbinden `$memberColorTokens` (Hex-Farben) und `$memberProgressValues` (0–100).
$memberDynamicRules = [];
foreach (array_unique(array_map('strval', (array) ($memberColorTokens ?? []))) as $memberColorToken) {
    $memberColorToken = MemberSanitizer::color($memberColorToken, '');
    if ($memberColorToken !== '') {
        $memberDynamicRules[] = '[data-member-color="' . $memberColorToken . '"] { --member-widget-color: ' . $memberColorToken . '; }';
    }
}
foreach (array_unique(array_map('intval', (array) ($memberProgressValues ?? []))) as $memberProgressValue) {
    $memberProgressValue = max(0, min(100, $memberProgressValue));
    $memberDynamicRules[] = '[data-member-progress="' . $memberProgressValue . '"] { --progress: ' . $memberProgressValue . '%; }';
}
?>
<!doctype html>
<html lang="<?= $e($htmlLang) ?>">
<head>
    <meta charset="utf-8">
    <?= function_exists('cms_csp_runtime_tags') ? cms_csp_runtime_tags() : '' ?>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex,nofollow">
    <title><?= $e($pageTitle) ?> – <?= $e($siteName) ?></title>
    <link rel="stylesheet" href="<?= $e(cms_asset_url('tabler/css/tabler.min.css')) ?>">
    <link rel="stylesheet" href="<?= $e(cms_asset_url('css/member-dashboard.css')) ?>">
    <?php foreach ((array) ($pageAssets['css'] ?? []) as $css): ?>
        <link rel="stylesheet" href="<?= $e((string) $css) ?>">
    <?php endforeach; ?>
    <style <?= \CMS\Security::instance()->nonceAttr() ?>>
        :root {
            --member-primary: <?= $e($memberPrimary) ?>;
            --member-accent: <?= $e($memberAccent) ?>;
            --member-bg: <?= $e($memberBg) ?>;
            --member-card-bg: <?= $e($memberCardBg) ?>;
            --member-text: <?= $e($memberText) ?>;
            --member-border: <?= $e($memberBorder) ?>;
        }
<?php foreach ($memberDynamicRules as $memberDynamicRule): ?>
        <?= $memberDynamicRule ?>

<?php endforeach; ?>
    </style>
    <?php \CMS\Hooks::doAction('head'); ?>
    <?php \CMS\Hooks::doAction('member_head', $pageKey, $controller->getCurrentUser()); ?>
</head>
<body class="<?= $e($bodyClass) ?>">
<a class="member-skip-link" href="#main-content"><?= $e($t('member.a11y.skip_to_content')) ?></a>
<?php \CMS\Hooks::doAction('body_start'); ?>
<div class="page member-layout">
    <aside class="navbar navbar-vertical navbar-expand-lg member-sidebar" aria-label="<?= $e($t('member.a11y.sidebar')) ?>">
        <div class="container-fluid">
            <button class="navbar-toggler member-sidebar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#member-sidebar-menu" aria-controls="member-sidebar-menu" aria-expanded="false">
                <span class="navbar-toggler-icon" aria-hidden="true"></span>
                <span class="visually-hidden"><?= $e($t('member.a11y.toggle_navigation')) ?></span>
            </button>
            <div class="navbar-brand navbar-brand-autodark member-brand">
                <a href="<?= $e($homeHref) ?>" class="text-reset text-decoration-none d-flex align-items-center gap-2">
                    <span class="d-inline-flex align-items-center justify-content-center rounded bg-white border p-1 member-brand-logo">
                        <img src="<?= $e($dashboardLogo) ?>" alt="" class="member-brand-logo__img" width="40" height="40">
                    </span>
                    <span>
                        <span class="d-block"><?= $e($siteName) ?></span>
                        <small class="text-secondary"><?= $e($t('member.common.hub')) ?></small>
                    </span>
                </a>
            </div>
            <div class="collapse navbar-collapse" id="member-sidebar-menu">
                <?php include __DIR__ . '/sidebar.php'; ?>
            </div>
        </div>
    </aside>
    <div class="page-wrapper member-page-wrapper">
        <header class="navbar navbar-expand-md d-print-none member-topbar">
            <div class="container-xl">
                <div class="navbar-nav flex-row order-md-last ms-auto align-items-center gap-2">
                    <div class="nav-item">
                        <a href="<?= $e($notificationsHref) ?>" class="nav-link px-2 member-topbar-notifications"<?= $pageKey === 'notifications' ? ' aria-current="page"' : '' ?>>
                            <span aria-hidden="true">🔔</span>
                            <span class="visually-hidden"><?= $e($t('member.nav.notifications')) ?></span>
                            <?php if ($unreadNotifications > 0): ?>
                                <span class="badge bg-red text-red-fg member-topbar-badge"><?= (int) $unreadNotifications ?><span class="visually-hidden"> <?= $e($t('member.notifications.unread_suffix')) ?></span></span>
                            <?php endif; ?>
                        </a>
                    </div>
                    <div class="nav-item dropdown">
                        <button type="button" class="nav-link d-flex lh-1 text-reset p-0 member-user-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true" id="member-user-menu-toggle">
                            <?php if ($avatar !== ''): ?>
                                <img class="avatar avatar-sm member-avatar-img" src="<?= $e($avatar) ?>" alt="" width="32" height="32">
                            <?php else: ?>
                                <span class="avatar avatar-sm" aria-hidden="true"><?= $e($controller->getInitials()) ?></span>
                            <?php endif; ?>
                            <span class="d-none d-md-block ps-2 text-start">
                                <span class="d-block"><?= $e($displayName) ?></span>
                                <span class="d-block mt-1 small text-secondary"><?= $e($t('member.common.area')) ?></span>
                            </span>
                            <span class="visually-hidden"><?= $e($t('member.a11y.user_menu')) ?></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow" aria-labelledby="member-user-menu-toggle">
                            <a href="<?= $e($profileHref) ?>" class="dropdown-item"><?= $e($t('member.nav.profile')) ?></a>
                            <a href="<?= $e($securityHref) ?>" class="dropdown-item"><?= $e($t('member.nav.security')) ?></a>
                            <div class="dropdown-divider"></div>
                            <a href="<?= $e($logoutHref) ?>" class="dropdown-item"><?= $e($t('member.nav.logout')) ?></a>
                        </div>
                    </div>
                </div>
                <div class="navbar-nav flex-row align-items-center gap-3 member-breadcrumb-wrap">
                    <div>
                        <div class="page-pretitle"><?= $e($t('member.common.area')) ?></div>
                        <h1 class="page-title mb-0"><?= $e($pageTitle) ?></h1>
                    </div>
                </div>
            </div>
        </header>
        <main id="main-content" class="page-body member-main" tabindex="-1">
            <div class="container-xl py-4">
                <?php include __DIR__ . '/alerts.php'; ?>
                <?php if ($showProfileGateNotice): ?>
                    <div class="alert alert-warning member-profile-gate" role="status">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div><?= $e($t('member.onboarding.gate_banner')) ?></div>
                            <a class="btn btn-sm btn-warning" href="<?= $e($profileHref) ?>"><?= $e($t('member.onboarding.gate_cta')) ?></a>
                        </div>
                    </div>
                <?php endif; ?>
