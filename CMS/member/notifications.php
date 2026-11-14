<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handleNotificationsRequest();

$pageTitle = $t('member.nav.notifications');
$pageKey = 'notifications';
$pageAssets = [];
$memberService = \CMS\Services\MemberService::getInstance();
$preferences = $memberService->getNotificationPreferences($controller->getUserId());
$notificationCenter = $controller->getNotificationCenterConfig();
$notificationCenterEnabled = !empty($notificationCenter['center_enabled']);
$recentNotifications = $controller->getNotificationCenterNotifications(20);
$unreadCount = $controller->getUnreadNotificationCount();
$readToken = $controller->csrfToken('notifications_read');
// NL-09: Ist der Core-Newsletter-Bereich registriert, verwaltet dessen Widget die Anmeldung.
$newsletterPath = $controller->getNewsletterSectionPath();

$groups = [
    'member.notifications.group_email' => [
        'email_notifications' => 'member.notifications.pref.email_notifications',
        'email_updates' => 'member.notifications.pref.email_updates',
        'email_security' => 'member.notifications.pref.email_security',
        'email_marketing' => 'member.notifications.pref.email_marketing',
    ],
    'member.notifications.group_inapp' => [
        'notify_messages' => 'member.notifications.pref.notify_messages',
        'notify_comments' => 'member.notifications.pref.notify_comments',
        'notify_events' => 'member.notifications.pref.notify_events',
        'notify_new_features' => 'member.notifications.pref.notify_new_features',
        'notify_promotions' => 'member.notifications.pref.notify_promotions',
    ],
    'member.notifications.group_devices' => [
        'browser_notifications' => 'member.notifications.pref.browser_notifications',
        'desktop_notifications' => 'member.notifications.pref.desktop_notifications',
        'mobile_notifications' => 'member.notifications.pref.mobile_notifications',
    ],
];

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-lg-7">
        <form class="card" method="post" action="" aria-labelledby="member-notification-settings-title">
            <input type="hidden" name="action" value="notifications_save">
            <input type="hidden" name="csrf_token" value="<?= $e($controller->csrfToken('notifications_save')) ?>">
            <div class="card-header"><h2 class="card-title" id="member-notification-settings-title"><?= $e($t('member.notifications.settings')) ?></h2></div>
            <div class="card-body vstack gap-4">
                <?php foreach ($groups as $groupLabel => $checkboxes): ?>
                    <fieldset>
                        <legend class="h4 mb-2"><?= $e($t($groupLabel)) ?></legend>
                        <div class="row g-2">
                            <?php foreach ($checkboxes as $key => $label): ?>
                                <?php if ($key === 'email_marketing' && $newsletterPath !== null): ?>
                                    <div class="col-12">
                                        <p class="form-hint mb-0">
                                            <?= $e($t('member.notifications.newsletter_hint')) ?>
                                            <a href="<?= $e($newsletterPath) ?>"><?= $e($t('member.notifications.newsletter_link')) ?></a>
                                        </p>
                                    </div>
                                    <?php continue; ?>
                                <?php endif; ?>
                                <div class="col-12 col-md-6">
                                    <label class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="<?= $e($key) ?>" value="1"<?= !empty($preferences[$key]) ? ' checked' : '' ?>>
                                        <span class="form-check-label"><?= $e($t($label)) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <div class="col-12 col-md-6 p-0">
                    <label class="form-label" for="notification_frequency"><?= $e($t('member.notifications.frequency')) ?></label>
                    <select class="form-select" id="notification_frequency" name="notification_frequency" aria-describedby="notification_frequency_hint">
                        <?php foreach (\CMS\Services\MemberService::NOTIFICATION_FREQUENCIES as $value): ?>
                            <option value="<?= $e($value) ?>"<?= (($preferences['notification_frequency'] ?? 'immediate') === $value) ? ' selected' : '' ?>><?= $e($t('member.notifications.frequency_' . $value)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-hint" id="notification_frequency_hint"><?= $e($t('member.notifications.email_pending_hint')) ?></div>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary"><?= $e($t('member.notifications.save')) ?></button>
            </div>
        </form>
    </div>

    <div class="col-12 col-lg-5">
        <section class="card" aria-labelledby="member-notification-list-title">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <h2 class="card-title" id="member-notification-list-title">
                    <?= $e($t('member.notifications.recent')) ?>
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge bg-red-lt"><?= (int) $unreadCount ?><span class="visually-hidden"> <?= $e($t('member.notifications.unread_suffix')) ?></span></span>
                    <?php endif; ?>
                </h2>
                <?php if ($notificationCenterEnabled && $unreadCount > 0): ?>
                    <form method="post" action="">
                        <input type="hidden" name="action" value="notifications_mark_all_read">
                        <input type="hidden" name="csrf_token" value="<?= $e($readToken) ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $e($t('member.notifications.mark_all_read')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if (!$notificationCenterEnabled): ?>
                <div class="card-body text-secondary"><?= $e($t('member.notifications.center_disabled')) ?></div>
            <?php elseif ($recentNotifications === []): ?>
                <div class="card-body">
                    <div class="member-empty">
                        <span class="member-empty__icon" aria-hidden="true">🔔</span>
                        <p class="member-empty__text mb-0"><?= $e($notificationCenter['empty_text'] ?? $t('member.notifications.empty')) ?></p>
                    </div>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recentNotifications as $notification): ?>
                        <?php
                        $isUnread = empty($notification->is_read);
                        $title = (string) (($notification->title ?? '') !== '' ? $notification->title : ($notification->message ?? ''));
                        ?>
                        <li class="list-group-item<?= $isUnread ? ' member-notification--unread' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-medium">
                                        <span class="badge <?= $isUnread ? 'bg-blue-lt' : 'bg-secondary-lt' ?> me-1"><?= $e($t($isUnread ? 'member.notifications.new' : 'member.notifications.read')) ?></span>
                                        <?php if (($notification->url ?? '') !== ''): ?>
                                            <a href="<?= $e($notification->url) ?>"><?= $e($title) ?></a>
                                        <?php else: ?>
                                            <?= $e($title) ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (($notification->message ?? '') !== '' && ($notification->title ?? '') !== ''): ?>
                                        <div class="text-secondary small"><?= nl2br($e($notification->message)) ?></div>
                                    <?php endif; ?>
                                    <div class="text-secondary small mt-1"><time datetime="<?= $e($notification->created_at ?? '') ?>"><?= $e(MemberI18n::formatDate($notification->created_at ?? '')) ?></time></div>
                                </div>
                                <?php if ($isUnread): ?>
                                    <form method="post" action="">
                                        <input type="hidden" name="action" value="notification_mark_read">
                                        <input type="hidden" name="notification_id" value="<?= (int) ($notification->id ?? 0) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $e($readToken) ?>">
                                        <button type="submit" class="btn btn-sm btn-ghost-secondary"><?= $e($t('member.notifications.mark_read')) ?><span class="visually-hidden">: <?= $e($title) ?></span></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
