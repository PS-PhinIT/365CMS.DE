<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$controller = $controller ?? \CMS\MemberArea\MemberController::instance();
$pageKey = $pageKey ?? 'dashboard';
$memberNavItems = $memberMenu ?? $controller->getMenuItems($pageKey);
$settings = $settings ?? $controller->getSettings();
$t ??= static fn (string $key, array $params = []): string => \CMS\Member\MemberI18n::t($key, $params);
$e ??= static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$memberNavGreetingTemplate = trim((string) ($settings['dashboard_greeting'] ?? ''));
$memberNavGreeting = str_replace('{name}', $controller->getDisplayName(), $memberNavGreetingTemplate !== '' ? $memberNavGreetingTemplate : $t('member.dashboard.greeting_default'));
$memberNavCategoryLabels = [
    'core' => $t('member.nav.group.core'),
    'content' => $t('member.nav.group.content'),
    'account' => $t('member.nav.group.account'),
    'plugins' => $t('member.nav.group.plugins'),
    'admin' => $t('member.nav.group.admin'),
];
$memberNavGroups = [];
foreach ($memberNavItems as $memberNavItem) {
    $memberNavGroups[(string) ($memberNavItem['category'] ?? 'plugins')][] = $memberNavItem;
}
?>
<div class="member-sidebar-inner w-100">
    <div class="member-sidebar-greeting card card-sm mb-3">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3">
                <span class="avatar avatar-lg" aria-hidden="true"><?= $e($controller->getInitials()) ?></span>
                <div>
                    <div class="fw-semibold"><?= $e($memberNavGreeting) ?></div>
                    <div class="text-secondary small"><?= $e($t('member.sidebar.tagline')) ?></div>
                </div>
            </div>
        </div>
    </div>
    <nav class="member-nav" aria-label="<?= $e($t('member.a11y.member_navigation')) ?>">
        <?php foreach ($memberNavGroups as $memberNavCategory => $memberNavGroupItems): ?>
            <?php $memberNavGroupId = 'member-nav-group-' . preg_replace('/[^a-z0-9_-]/i', '', (string) $memberNavCategory); ?>
            <?php if (count($memberNavGroups) > 1): ?>
                <div class="member-nav-group-title" id="<?= $e($memberNavGroupId) ?>"><?= $e($memberNavCategoryLabels[$memberNavCategory] ?? ucfirst((string) $memberNavCategory)) ?></div>
            <?php endif; ?>
            <ul class="navbar-nav member-nav-list"<?= count($memberNavGroups) > 1 ? ' aria-labelledby="' . $e($memberNavGroupId) . '"' : '' ?>>
                <?php foreach ($memberNavGroupItems as $memberNavItem): ?>
                    <?php $memberNavChildren = is_array($memberNavItem['children'] ?? null) ? $memberNavItem['children'] : []; ?>
                    <li class="nav-item<?= !empty($memberNavItem['active']) ? ' active' : '' ?>">
                        <a class="nav-link" href="<?= $e($memberNavItem['url'] ?? '#') ?>"<?= !empty($memberNavItem['active']) ? ' aria-current="page"' : '' ?>>
                            <span class="nav-link-icon d-md-none d-lg-inline-block" aria-hidden="true"><?= $e($memberNavItem['icon'] ?? '•') ?></span>
                            <span class="nav-link-title"><?= $e($memberNavItem['label'] ?? '') ?></span>
                        </a>
                        <?php if ($memberNavChildren !== []): ?>
                            <ul class="member-nav-sublist">
                                <?php foreach ($memberNavChildren as $memberNavChild): ?>
                                    <li class="nav-item<?= !empty($memberNavChild['active']) ? ' active' : '' ?>">
                                        <a class="nav-link" href="<?= $e($memberNavChild['url'] ?? '#') ?>"<?= !empty($memberNavChild['active']) ? ' aria-current="page"' : '' ?>>
                                            <span class="nav-link-title"><?= $e($memberNavChild['label'] ?? '') ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>
</div>
