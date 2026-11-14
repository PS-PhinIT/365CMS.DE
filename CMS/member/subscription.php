<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/includes/bootstrap.php';

if (class_exists('\CMS\Services\CoreModuleService')
    && !\CMS\Services\CoreModuleService::getInstance()->isModuleEnabled('subscription_member_area')) {
    $controller->redirect($controller->getHomePath());
}

$pageTitle = $t('member.nav.subscription');
$pageKey = 'subscription';
$pageAssets = [];
$memberService = \CMS\Services\MemberService::getInstance();
$subscription = $memberService->getUserSubscription($controller->getUserId());
$renewalNotice = $memberService->getSubscriptionRenewalNotice($controller->getUserId());
$packages = $memberService->getAvailablePackages();
$orders = $controller->getOrders();
$renewalSeverity = (string) ($renewalNotice['severity'] ?? 'info');
$renewalAlertClass = match ($renewalSeverity) {
    'danger' => 'alert-danger',
    'warning' => 'alert-warning',
    default => 'alert-info',
};
$isEnglish = MemberI18n::locale() === 'en';
$money = static fn (float $amount): string => '€ ' . number_format($amount, 2, $isEnglish ? '.' : ',', $isEnglish ? ',' : '.');

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-xl-4 vstack gap-4">
        <section class="card" aria-labelledby="member-subscription-active-title">
            <div class="card-header"><h2 class="card-title" id="member-subscription-active-title"><?= $e($t('member.subscription.active')) ?></h2></div>
            <div class="card-body">
                <?php if ($subscription !== null): ?>
                    <p class="display-6 mb-2"><?= $e($subscription->package_name ?? $subscription->name ?? '') ?></p>
                    <?php if (!empty($subscription->status)): ?>
                        <div class="badge bg-primary-lt mb-3"><?= $e($subscription->status) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($renewalNotice['due_label'])): ?>
                        <p class="text-secondary mb-3">
                            <?= $e($t(!empty($renewalNotice['is_auto_renewal']) ? 'member.subscription.next_renewal' : 'member.subscription.runs_until', ['date' => (string) $renewalNotice['due_label']])) ?>
                        </p>
                    <?php elseif (!empty($subscription->end_date)): ?>
                        <p class="text-secondary mb-3"><?= $e($t('member.subscription.runs_until', ['date' => MemberI18n::formatDate((string) $subscription->end_date, false)])) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($renewalNotice['has_notice'])): ?>
                        <div class="alert <?= $e($renewalAlertClass) ?> mb-0" role="<?= $renewalSeverity === 'danger' ? 'alert' : 'status' ?>">
                            <?php if (!empty($renewalNotice['title'])): ?>
                                <div class="fw-semibold mb-1"><?= $e($renewalNotice['title']) ?></div>
                            <?php endif; ?>
                            <div><?= $e($renewalNotice['message'] ?? '') ?></div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-secondary mb-0"><?= $e($t('member.subscription.none')) ?></p>
                <?php endif; ?>
            </div>
        </section>
        <section class="card" aria-labelledby="member-subscription-packages-title">
            <div class="card-header"><h2 class="card-title" id="member-subscription-packages-title"><?= $e($t('member.subscription.packages')) ?></h2></div>
            <?php if ($packages === []): ?>
                <div class="card-body text-secondary"><?= $e($t('member.subscription.packages_empty')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($packages as $package): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <div class="fw-medium"><?= $e($package->name ?? '') ?></div>
                                    <div class="text-secondary small"><?= $e($package->description ?? '') ?></div>
                                </div>
                                <?php if (isset($package->price_monthly)): ?>
                                    <div class="text-end">
                                        <div class="fw-medium"><?= $e($money((float) $package->price_monthly)) ?></div>
                                        <div class="text-secondary small"><?= $e($t('member.subscription.monthly')) ?></div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-12 col-xl-8">
        <section class="card" aria-labelledby="member-subscription-orders-title">
            <div class="card-header"><h2 class="card-title" id="member-subscription-orders-title"><?= $e($t('member.subscription.orders')) ?></h2></div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <caption class="visually-hidden"><?= $e($t('member.subscription.orders')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= $e($t('member.subscription.col_order')) ?></th>
                            <th scope="col"><?= $e($t('member.subscription.col_package')) ?></th>
                            <th scope="col"><?= $e($t('member.subscription.col_status')) ?></th>
                            <th scope="col"><?= $e($t('member.subscription.col_amount')) ?></th>
                            <th scope="col"><?= $e($t('member.subscription.col_date')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($orders === []): ?>
                            <tr><td colspan="5" class="text-secondary"><?= $e($t('member.subscription.orders_empty')) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $order): ?>
                                <?php
                                $status = strtolower(trim((string)($order->normalized_status ?? $order->status ?? 'pending')));
                                $knownStatus = in_array($status, ['paid', 'pending', 'cancelled', 'refunded', 'failed'], true);
                                $statusLabel = $knownStatus ? $t('member.subscription.status_' . $status) : ($status !== '' ? $status : $t('member.common.unknown'));
                                $statusClass = match ($status) {
                                    'paid' => 'green',
                                    'pending' => 'yellow',
                                    'cancelled', 'failed' => 'red',
                                    'refunded' => 'azure',
                                    default => 'secondary',
                                };
                                ?>
                                <tr>
                                    <td>
                                        <div class="fw-medium"><?= $e($order->order_number ?? ('#' . (int)($order->id ?? 0))) ?></div>
                                        <div class="text-secondary small"><?= $e($order->resolved_customer_email ?? $order->customer_email ?? $order->email ?? '') ?></div>
                                    </td>
                                    <td><?= $e(($order->plan_name ?? '') !== '' ? $order->plan_name : $t('member.common.unknown')) ?></td>
                                    <td><span class="badge bg-<?= $e($statusClass) ?>-lt"><?= $e($statusLabel) ?></span></td>
                                    <td><?= isset($order->display_amount) ? $e($money((float) $order->display_amount)) : '–' ?></td>
                                    <td><?= $e(MemberI18n::formatDate($order->created_at ?? '', false)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
