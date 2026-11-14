<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;
use CMS\Member\MemberSanitizer;
use CMS\MemberArea\MemberController;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

// Ohne Dashboard direkt auf die Startseite, bevor Daten geladen werden (MD-03).
if (empty($settings['dashboard_enabled'])) {
    $controller->redirect($controller->getHomePath());
}

$pageTitle = $t('member.nav.dashboard');
$pageKey = 'dashboard';
$pageAssets = [];
$data = $controller->getDashboardData();
$settings = is_array($data['settings'] ?? null) ? $data['settings'] : $settings;
$layout = is_array($data['layout'] ?? null) ? $data['layout'] : $controller->getDashboardLayout();
$coreWidgets = array_flip((array) ($layout['widgets'] ?? []));
$columnClass = MemberController::widgetColumnClass((int) ($layout['columns'] ?? 3));

$notifications = is_array($data['recent_notifications'] ?? null) ? $data['recent_notifications'] : [];
$activity = is_array($data['recent_activity'] ?? null) ? $data['recent_activity'] : [];
$pluginWidgets = is_array($data['plugin_widgets'] ?? null) ? $data['plugin_widgets'] : [];
$customWidgets = is_array($data['custom_widgets'] ?? null) ? $data['custom_widgets'] : [];
$favorites = is_array($data['favorites'] ?? null) ? $data['favorites'] : [];
$security = is_array($data['security'] ?? null) ? $data['security'] : [];
$profileCompletion = is_array($data['profile_completion'] ?? null) ? $data['profile_completion'] : ['percentage' => 0, 'missing' => []];
$profilePercentage = max(0, min(100, (int) ($profileCompletion['percentage'] ?? 0)));
$profileMissingLabels = is_array($profileCompletion['missing_labels'] ?? null) ? $profileCompletion['missing_labels'] : [];
$subscription = $data['subscription'] ?? null;
$frontendModules = is_array($settings['frontend_modules'] ?? null) ? $settings['frontend_modules'] : [];
$notificationCenter = is_array($data['notification_center'] ?? null) ? $data['notification_center'] : [];
$moduleOn = static fn (string $key): bool => !array_key_exists($key, $frontendModules) || !empty($frontendModules[$key]);
$widgetOn = static fn (string $key): bool => isset($coreWidgets[$key]);
$showWelcome = !empty($settings['show_welcome']);
$showQuickstart = $moduleOn('show_quickstart') && $widgetOn('quick_links');
$showStats = $moduleOn('show_stats') && $widgetOn('statistics');
$showCustomWidgets = $moduleOn('show_custom_widgets');
$showPluginWidgets = $moduleOn('show_plugin_widgets');
$showNotifications = $moduleOn('show_notifications_panel') && $widgetOn('notifications') && !empty($notificationCenter['center_enabled']);
$onboarding = is_array($settings['onboarding'] ?? null) ? $settings['onboarding'] : [];
$showOnboarding = $moduleOn('show_onboarding_panel') && !empty($onboarding['enabled']);
$onboardingSteps = array_values(array_filter(array_map('strval', (array) ($onboarding['steps'] ?? []))));
$onboardingCtaHref = MemberSanitizer::link($onboarding['cta_url'] ?? '/member/profile', '/member/profile');
$isAdminViewer = $controller->canAccessAdminPortal();
$greetingTemplate = trim((string) ($settings['dashboard_greeting'] ?? ''));
$greeting = str_replace('{name}', $controller->getDisplayName(), $greetingTemplate !== '' ? $greetingTemplate : $t('member.dashboard.greeting_default'));
$twoFactorEnabled = !empty($data['two_factor_enabled']);

/** KPI-Karte: Wert und Bezeichnung als Text, Farbe nur unterstützend (UI-06). */
$renderKpi = static function (string $value, string $label, string $hint, string $href, string $icon, string $tone) use ($e): void {
    ?>
    <div class="col-12 col-sm-6 col-xl-3">
        <a class="card card-sm member-kpi member-kpi--<?= $e($tone) ?> text-reset text-decoration-none h-100" href="<?= $e($href) ?>">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="avatar member-kpi__icon" aria-hidden="true"><?= $e($icon) ?></span>
                <span class="d-block">
                    <span class="d-block member-kpi__value"><?= $e($value) ?></span>
                    <span class="d-block member-kpi__label"><?= $e($label) ?></span>
                    <span class="d-block member-kpi__hint text-secondary small"><?= $e($hint) ?></span>
                </span>
            </div>
        </a>
    </div>
    <?php
};

/** Leerzustand: dekoratives Icon, ein Satz, optional eine Aktion (UI-11). */
$renderEmpty = static function (string $icon, string $text, string $actionHref = '', string $actionLabel = '') use ($e): void {
    ?>
    <div class="member-empty">
        <span class="member-empty__icon" aria-hidden="true"><?= $e($icon) ?></span>
        <p class="member-empty__text mb-0"><?= $e($text) ?></p>
        <?php if ($actionHref !== '' && $actionLabel !== ''): ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= $e($actionHref) ?>"><?= $e($actionLabel) ?></a>
        <?php endif; ?>
    </div>
    <?php
};

$memberProgressValues = [$profilePercentage];
$memberColorTokens = array_map(static fn (array $widget): string => (string) ($widget['color'] ?? ''), $pluginWidgets);

include __DIR__ . '/partials/header.php';
?>
<div class="member-dashboard vstack gap-4">
    <?php if ($showWelcome): ?>
        <section class="card member-hero-card" aria-labelledby="member-welcome-title">
            <div class="card-body p-4 p-lg-5">
                <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                        <p class="page-pretitle mb-1"><?= $e($t('member.dashboard.overview_pretitle')) ?></p>
                        <h2 class="card-title h2 mb-2" id="member-welcome-title"><?= $e($greeting) ?></h2>
                        <div class="text-secondary member-welcome-text"><?= $controller->getWelcomeHtml() ?></div>
                    </div>
                    <div class="col-lg-4 text-lg-end">
                        <a href="/member/profile" class="btn btn-primary"><?= $e($t($profilePercentage < 100 ? 'member.dashboard.complete_profile' : 'member.dashboard.edit_profile')) ?></a>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php foreach ((array) ($layout['section_order'] ?? []) as $section): ?>
        <?php if ($section === 'quick_start' && $showQuickstart): ?>
            <section class="card" aria-labelledby="member-quickstart-title">
                <div class="card-header"><h2 class="card-title" id="member-quickstart-title"><?= $e($t('member.dashboard.quickstart')) ?></h2></div>
                <div class="card-body">
                    <ul class="row g-3 list-unstyled mb-0">
                        <?php foreach ([
                            ['/member/profile', 'member.nav.profile', 'member.dashboard.quick_profile'],
                            ['/member/security', 'member.nav.security', 'member.dashboard.quick_security'],
                            ['/member/media', 'member.nav.media', 'member.dashboard.quick_media'],
                        ] as [$quickHref, $quickTitle, $quickText]): ?>
                            <li class="col-12 col-sm-6 col-lg-4">
                                <a class="card card-link card-link-pop h-100" href="<?= $e($quickHref) ?>">
                                    <span class="card-body d-block">
                                        <span class="d-block h3 mb-1"><?= $e($t($quickTitle)) ?></span>
                                        <span class="d-block text-secondary"><?= $e($t($quickText)) ?></span>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </section>
        <?php elseif ($section === 'stats' && $showStats): ?>
            <section aria-labelledby="member-stats-title">
                <h2 class="visually-hidden" id="member-stats-title"><?= $e($t('member.dashboard.stats')) ?></h2>
                <div class="row g-3">
                    <?php
                    $renderKpi((string) (int) ($data['login_count_30d'] ?? 0), $t('member.kpi.logins'), $t('member.kpi.logins_hint'), '/member/security', '👋', 'primary');
                    $score = (int) ($security['score'] ?? 0);
                    $renderKpi($score . '/100', $t('member.kpi.security_score'), (string) ($security['score_message'] ?? ''), '/member/security', '🛡️', $score >= 85 ? 'success' : ($score >= 50 ? 'warning' : 'danger'));
                    $renderKpi((string) (int) ($data['unread_messages'] ?? 0), $t('member.kpi.unread_messages'), $t('member.kpi.unread_messages_hint'), '/member/messages', '✉️', 'info');
                    $renderKpi((string) (int) ($data['active_sessions'] ?? 0), $t('member.kpi.sessions'), $t('member.kpi.sessions_hint', ['time' => (string) ($data['last_login_relative'] ?? '–')]), '/member/security', '💻', 'secondary');
                    ?>
                </div>
            </section>
        <?php elseif ($section === 'widgets' && $showCustomWidgets && ($customWidgets !== [] || $isAdminViewer)): ?>
            <section aria-labelledby="member-info-widgets-title">
                <h2 class="h3 mb-3" id="member-info-widgets-title"><?= $e($t('member.dashboard.info_widgets')) ?></h2>
                <?php if ($customWidgets === []): ?>
                    <?php $renderEmpty('✨', $t('member.dashboard.info_widgets_empty'), '/admin/member-dashboard-widgets', $t('member.dashboard.configure')); ?>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($customWidgets as $index => $widget): ?>
                            <div class="<?= $e($columnClass) ?>">
                                <article class="card h-100" aria-labelledby="member-info-widget-<?= (int) $index ?>">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <span class="avatar avatar-sm" aria-hidden="true"><?= $e($widget['icon'] ?? '✨') ?></span>
                                            <h3 class="card-title mb-0" id="member-info-widget-<?= (int) $index ?>"><?= $e(($widget['title'] ?? '') !== '' ? $widget['title'] : $t('member.dashboard.info_widget')) ?></h3>
                                        </div>
                                        <div class="text-secondary member-widget-content"><?= (string) ($widget['content_html'] ?? '') ?></div>
                                    </div>
                                </article>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php elseif ($section === 'plugins' && $showPluginWidgets && ($pluginWidgets !== [] || $isAdminViewer)): ?>
            <section aria-labelledby="member-plugin-widgets-title">
                <h2 class="h3 mb-3" id="member-plugin-widgets-title"><?= $e($t('member.dashboard.plugin_widgets')) ?></h2>
                <?php if ($pluginWidgets === []): ?>
                    <?php $renderEmpty('🔌', $t('member.dashboard.plugin_widgets_empty'), '/admin/member-dashboard-plugin-widgets', $t('member.dashboard.configure')); ?>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($pluginWidgets as $index => $widget): ?>
                            <?php $anchor = (string) ($widget['anchor'] ?? ''); ?>
                            <div class="<?= $e($columnClass) ?>">
                                <?php if (isset($widget['html']) && is_string($widget['html']) && $widget['html'] !== ''): ?>
                                    <?php /* Vertrauenswürdiges, bereits escaptes Markup aus dem render_callback der PluginDashboardRegistry;
                                       es bringt seine eigene Wurzel-ID mit (z. B. id="newsletter"), daher hier kein id-Attribut. */ ?>
                                    <div class="member-widget member-widget--custom h-100">
                                        <?= $widget['html'] ?>
                                    </div>
                                <?php else: ?>
                                    <?php $titleId = 'member-plugin-widget-' . (int) $index; ?>
                                    <article class="card h-100 member-plugin-widget member-widget"<?= $anchor !== '' ? ' id="' . $e($anchor) . '"' : '' ?> aria-labelledby="<?= $e($titleId) ?>">
                                        <div class="card-body d-flex flex-column">
                                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                                <div>
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                                        <h3 class="card-title mb-0" id="<?= $e($titleId) ?>"><?= $e($widget['title'] ?? 'Plugin') ?></h3>
                                                        <?php if (!empty($widget['badge'])): ?>
                                                            <span class="badge bg-primary-lt"><?= $e($widget['badge']) ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (($widget['description'] ?? '') !== ''): ?>
                                                        <p class="text-secondary mb-0"><?= $e($widget['description']) ?></p>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="avatar member-widget-icon" data-member-color="<?= $e(MemberSanitizer::color($widget['color'] ?? '')) ?>" aria-hidden="true"><?= $e($widget['icon'] ?? '🔌') ?></span>
                                            </div>
                                            <?php if (!empty($widget['stats']) && is_array($widget['stats'])): ?>
                                                <p class="mb-3">
                                                    <span class="d-block h2 mb-1"><?= (int) ($widget['stats']['count'] ?? 0) ?></span>
                                                    <span class="d-block text-secondary"><?= $e($widget['stats']['label'] ?? '') ?></span>
                                                </p>
                                            <?php endif; ?>
                                            <div class="d-flex flex-wrap gap-2 mt-auto">
                                                <a href="<?= $e($widget['link'] ?? '/member/dashboard') ?>" class="btn btn-outline-primary btn-sm">
                                                    <?= $e($widget['link_label'] ?? $t('member.widget.open')) ?><span class="visually-hidden">: <?= $e($widget['title'] ?? '') ?></span>
                                                </a>
                                                <?php if (!empty($widget['admin_link'])): ?>
                                                    <a href="<?= $e($widget['admin_link']) ?>" class="btn btn-ghost-secondary btn-sm">
                                                        <?= $e($widget['admin_label'] ?? $t('member.widget.manage')) ?><span class="visually-hidden">: <?= $e($widget['title'] ?? '') ?></span>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </article>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endforeach; ?>

    <div class="row g-4">
        <div class="col-12 col-lg-8 vstack gap-4">
            <?php if ($showOnboarding): ?>
                <section class="card" aria-labelledby="member-onboarding-title">
                    <div class="card-header"><h2 class="card-title" id="member-onboarding-title"><?= $e(($onboarding['title'] ?? '') !== '' ? $onboarding['title'] : $t('member.onboarding.title_default')) ?></h2></div>
                    <div class="card-body">
                        <?php if (trim((string) ($onboarding['intro'] ?? '')) !== ''): ?>
                            <p class="text-secondary"><?= $e($onboarding['intro']) ?></p>
                        <?php endif; ?>
                        <?php if ($onboardingSteps !== []): ?>
                            <ol class="mb-3 member-checklist">
                                <?php foreach ($onboardingSteps as $step): ?>
                                    <li><?= $e($step) ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                        <a href="<?= $e($onboardingCtaHref) ?>" class="btn btn-outline-primary"><?= $e(($onboarding['cta_label'] ?? '') !== '' ? $onboarding['cta_label'] : $t('member.onboarding.cta_default')) ?></a>
                    </div>
                </section>
            <?php endif; ?>

            <?php foreach ((array) ($layout['widgets'] ?? []) as $coreWidget): ?>
                <?php if ($coreWidget === 'bookmarks'): ?>
                    <section class="card" aria-labelledby="member-favorites-title">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2">
                            <h2 class="card-title" id="member-favorites-title"><?= $e($t('member.favorites.title')) ?></h2>
                            <a href="/member/favorites" class="btn btn-sm btn-outline-primary"><?= $e($t('member.common.show_all')) ?><span class="visually-hidden">: <?= $e($t('member.favorites.title')) ?></span></a>
                        </div>
                        <?php if ($favorites === []): ?>
                            <div class="card-body"><?php $renderEmpty('⭐', $t('member.favorites.empty')); ?></div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($favorites as $favorite): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center gap-3">
                                        <a href="<?= $e($favorite['url'] ?? '#') ?>"><?= $e($favorite['title'] ?? '') ?></a>
                                        <span class="text-secondary small text-nowrap"><?= $e(MemberI18n::formatDate($favorite['created_at'] ?? '', false)) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                <?php elseif ($coreWidget === 'messages'): ?>
                    <section class="card" aria-labelledby="member-messages-title">
                        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <h2 class="card-title mb-1" id="member-messages-title"><?= $e($t('member.nav.messages')) ?></h2>
                                <p class="text-secondary mb-0"><?= $e($t('member.dashboard.messages_unread', ['count' => (int) ($data['unread_messages'] ?? 0)])) ?></p>
                            </div>
                            <a href="/member/messages" class="btn btn-outline-primary"><?= $e($t('member.dashboard.open_inbox')) ?></a>
                        </div>
                    </section>
                <?php elseif ($coreWidget === 'activity'): ?>
                    <section class="card" aria-labelledby="member-activity-title">
                        <div class="card-header"><h2 class="card-title" id="member-activity-title"><?= $e($t('member.dashboard.activity')) ?></h2></div>
                        <div class="card-body">
                            <?php if ($activity === []): ?>
                                <?php $renderEmpty('🕑', $t('member.dashboard.activity_empty')); ?>
                            <?php else: ?>
                                <ul class="timeline mb-0">
                                    <?php foreach ($activity as $entry): ?>
                                        <li class="timeline-event">
                                            <span class="timeline-event-icon bg-primary-lt" aria-hidden="true">•</span>
                                            <div class="card timeline-event-card">
                                                <div class="card-body py-3">
                                                    <div class="text-body fw-medium"><?= $e($entry->description ?? $entry->action ?? '') ?></div>
                                                    <div class="text-secondary small"><?= $e(MemberI18n::formatDate($entry->created_at ?? '')) ?></div>
                                                </div>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </section>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="col-12 col-lg-4 vstack gap-4">
            <?php foreach ((array) ($layout['widgets'] ?? []) as $coreWidget): ?>
                <?php if ($coreWidget === 'profile'): ?>
                    <section class="card" aria-labelledby="member-profile-progress-title">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h2 class="card-title" id="member-profile-progress-title"><?= $e($t('member.dashboard.profile_progress')) ?></h2>
                            <span class="badge bg-primary-lt"><?= $profilePercentage ?>%</span>
                        </div>
                        <div class="card-body">
                            <div class="progress progress-separated mb-3">
                                <div class="progress-bar bg-primary member-progress" data-member-progress="<?= $profilePercentage ?>" role="progressbar" aria-valuenow="<?= $profilePercentage ?>" aria-valuemin="0" aria-valuemax="100" aria-labelledby="member-profile-progress-title"></div>
                            </div>
                            <?php if ($profileMissingLabels !== []): ?>
                                <p class="text-secondary small mb-2"><?= $e($t('member.dashboard.profile_missing')) ?></p>
                                <ul class="d-flex flex-wrap gap-2 list-unstyled mb-0">
                                    <?php foreach ($profileMissingLabels as $label): ?>
                                        <li class="badge bg-secondary-lt"><?= $e($label) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="mb-0"><?= $e($t('member.dashboard.profile_complete')) ?></p>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="card" aria-labelledby="member-security-status-title">
                        <div class="card-header"><h2 class="card-title" id="member-security-status-title"><?= $e($t('member.dashboard.security_status')) ?></h2></div>
                        <div class="card-body">
                            <dl class="row mb-3">
                                <dt class="col-7 text-secondary"><?= $e($t('member.dashboard.two_factor')) ?></dt>
                                <dd class="col-5 text-end mb-2">
                                    <span class="badge <?= $twoFactorEnabled ? 'bg-green-lt text-green' : 'bg-amber-lt text-amber' ?>"><?= $e($t($twoFactorEnabled ? 'member.common.active' : 'member.common.inactive')) ?></span>
                                </dd>
                                <dt class="col-7 text-secondary"><?= $e($t('member.dashboard.password_changed')) ?></dt>
                                <dd class="col-5 text-end mb-0"><?= $e((string) ($data['password_changed_relative'] ?? '–')) ?></dd>
                            </dl>
                            <a href="/member/security" class="btn btn-sm btn-outline-primary"><?= $e($t('member.dashboard.open_security')) ?></a>
                        </div>
                    </section>

                    <section class="card" aria-labelledby="member-account-title">
                        <div class="card-header"><h2 class="card-title" id="member-account-title"><?= $e($t('member.dashboard.account_status')) ?></h2></div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-6 text-secondary"><?= $e($t('member.dashboard.last_login')) ?></dt>
                                <dd class="col-6 text-end"><?= $e((string) ($data['last_login_formatted'] ?? '–')) ?></dd>
                                <dt class="col-6 text-secondary"><?= $e($t('member.dashboard.member_since')) ?></dt>
                                <dd class="col-6 text-end"><?= $e($t('member.dashboard.days', ['count' => (int) ($data['account_age_days'] ?? 0)])) ?></dd>
                                <?php if (!empty($data['subscription_module_enabled'])): ?>
                                    <dt class="col-6 text-secondary"><?= $e($t('member.dashboard.subscription')) ?></dt>
                                    <dd class="col-6 text-end mb-0"><?= $e(is_object($subscription) ? (string) ($subscription->package_name ?? $subscription->name ?? '') : $t('member.dashboard.subscription_none')) ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </section>
                <?php elseif ($coreWidget === 'notifications' && $showNotifications): ?>
                    <section class="card" aria-labelledby="member-notifications-title">
                        <div class="card-header d-flex justify-content-between align-items-center gap-2">
                            <h2 class="card-title" id="member-notifications-title">
                                <?= $e($t('member.dashboard.notifications')) ?>
                                <?php if ((int) ($data['unread_notifications'] ?? 0) > 0): ?>
                                    <span class="badge bg-red-lt"><?= (int) $data['unread_notifications'] ?><span class="visually-hidden"> <?= $e($t('member.notifications.unread_suffix')) ?></span></span>
                                <?php endif; ?>
                            </h2>
                            <a href="/member/notifications" class="btn btn-sm btn-outline-primary"><?= $e($t('member.common.manage')) ?><span class="visually-hidden">: <?= $e($t('member.dashboard.notifications')) ?></span></a>
                        </div>
                        <?php if ($notifications === []): ?>
                            <div class="card-body"><?php $renderEmpty('🔔', (string) ($notificationCenter['empty_text'] ?? $t('member.notifications.empty'))); ?></div>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($notifications as $notification): ?>
                                    <?php $notificationTitle = (string) (($notification->title ?? '') !== '' ? $notification->title : ($notification->message ?? '')); ?>
                                    <li class="list-group-item<?= empty($notification->is_read) ? ' member-notification--unread' : '' ?>">
                                        <?php if (empty($notification->is_read)): ?>
                                            <span class="badge bg-blue-lt me-1"><?= $e($t('member.notifications.new')) ?></span>
                                        <?php endif; ?>
                                        <?php if (($notification->url ?? '') !== ''): ?>
                                            <a href="<?= $e($notification->url) ?>" class="text-body"><?= $e($notificationTitle) ?></a>
                                        <?php else: ?>
                                            <span class="text-body"><?= $e($notificationTitle) ?></span>
                                        <?php endif; ?>
                                        <div class="text-secondary small"><time datetime="<?= $e($notification->created_at ?? '') ?>"><?= $e(MemberI18n::formatDate($notification->created_at ?? '')) ?></time></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
