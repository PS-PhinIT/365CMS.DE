<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Übersicht.
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var callable $nlDate
 * @var callable $nlNumber
 * @var array<string, string> $nlStatusBadge
 */

$nlStats = (array) ($nlData['stats'] ?? []);
$nlCards = [
    ['newsletter.admin.stat_active', (int) ($nlStats['active'] ?? 0), 'user-check', NewsletterModule::url(['section' => 'subscribers', 'status' => 'active'])],
    ['newsletter.admin.stat_pending', (int) ($nlStats['pending'] ?? 0), 'hourglass', NewsletterModule::url(['section' => 'subscribers', 'status' => 'pending'])],
    ['newsletter.admin.stat_new_30', (int) ($nlStats['new_30'] ?? 0), 'trending-up', ''],
    ['newsletter.admin.stat_sent_campaigns', (int) ($nlStats['sent_campaigns'] ?? 0), 'send', NewsletterModule::url(['section' => 'campaigns', 'status' => 'sent'])],
];
?>
<?php if (empty($nlData['sender_ok'])): ?>
    <div class="alert alert-warning" role="alert"><?= $nlT('newsletter.admin.sender_missing') ?> <a href="<?= $nlE(NewsletterModule::url(['section' => 'settings'])) ?>"><?= $nlT('newsletter.admin.tab_settings') ?></a></div>
<?php endif; ?>
<div class="row row-cards mb-4">
    <?php foreach ($nlCards as [$nlLabel, $nlValue, $nlIcon, $nlHref]): ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card card-sm"><div class="card-body d-flex align-items-center gap-3">
                <span class="avatar bg-primary-lt"><i class="ti ti-<?= $nlE($nlIcon) ?>" aria-hidden="true"></i></span>
                <div>
                    <div class="h2 mb-0"><?= $nlE($nlNumber($nlValue)) ?></div>
                    <div class="text-secondary"><?php if ($nlHref !== ''): ?><a class="text-reset" href="<?= $nlE($nlHref) ?>"><?= $nlT($nlLabel) ?></a><?php else: ?><?= $nlT($nlLabel) ?><?php endif; ?></div>
                </div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.latest_campaigns') ?></h3><div class="card-actions"><a href="<?= $nlE(NewsletterModule::url(['section' => 'campaigns'])) ?>"><?= $nlT('newsletter.admin.show_all') ?></a></div></div>
            <?php if (empty($nlData['campaigns'])): ?>
                <div class="card-body text-secondary"><?= $nlT('newsletter.admin.campaigns_empty') ?></div>
            <?php else: ?>
                <div class="table-responsive"><table class="table table-vcenter card-table">
                    <thead><tr><th><?= $nlT('newsletter.admin.col_campaign') ?></th><th><?= $nlT('newsletter.admin.col_status') ?></th><th class="text-end"><?= $nlT('newsletter.admin.col_sent') ?></th><th><?= $nlT('newsletter.admin.col_date') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ((array) $nlData['campaigns'] as $nlRow): ?>
                        <tr>
                            <td><a href="<?= $nlE(NewsletterModule::url(['section' => 'campaign', 'id' => (int) $nlRow['id']])) ?>"><?= $nlE($nlRow['name']) ?></a><div class="small text-secondary"><?= $nlE($nlRow['subject']) ?></div></td>
                            <td><span class="badge <?= $nlE($nlStatusBadge[$nlRow['status']] ?? 'bg-secondary-lt') ?>"><?= $nlT('newsletter.status.' . $nlRow['status']) ?></span></td>
                            <td class="text-end"><?= (int) $nlRow['sent_count'] ?> / <?= (int) $nlRow['recipient_count'] ?></td>
                            <td class="text-secondary text-nowrap"><?= $nlE($nlDate($nlRow['sent_at'] ?? ($nlRow['scheduled_at'] ?? $nlRow['updated_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.segments_title') ?></h3></div>
            <div class="list-group list-group-flush">
                <?php foreach ((array) $nlData['segments'] as $nlSlug => $nlLabels): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= $nlE(NewsletterModule::url(['section' => 'subscribers', 'segment' => $nlSlug, 'status' => 'active'])) ?>">
                        <span><?= $nlE($nlLabels['de']) ?> <code class="small"><?= $nlE($nlSlug) ?></code></span><span class="text-secondary"><?= (int) (($nlData['segment_counts'] ?? [])[$nlSlug] ?? 0) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.recent_subscribers') ?></h3></div>
            <?php if (empty($nlData['recent'])): ?>
                <div class="card-body text-secondary"><?= $nlT('newsletter.admin.subscribers_empty') ?></div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ((array) $nlData['recent'] as $nlRow): ?>
                        <a class="list-group-item list-group-item-action" href="<?= $nlE(NewsletterModule::url(['section' => 'subscriber', 'id' => (int) $nlRow['id']])) ?>">
                            <div class="text-truncate"><?= $nlE($nlRow['email']) ?></div>
                            <div class="small text-secondary"><span class="badge <?= $nlE($nlStatusBadge[$nlRow['status']] ?? '') ?>"><?= $nlT('newsletter.status.' . $nlRow['status']) ?></span> <?= $nlE($nlDate($nlRow['created_at'])) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
