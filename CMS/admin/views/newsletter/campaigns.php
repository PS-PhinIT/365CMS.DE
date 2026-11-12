<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Kampagnenliste mit Statusfilter.
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var callable $nlDate
 * @var array<string, string> $nlStatusBadge
 */

$nlCurrent = (string) ($nlData['status'] ?? '');
?>
<ul class="nav nav-pills mb-3">
    <?php foreach (array_merge([''], \CMS\Services\Newsletter\NewsletterRepository::CAMPAIGN_STATUSES) as $nlS): ?>
        <li class="nav-item"><a class="nav-link<?= $nlCurrent === $nlS ? ' active' : '' ?>"<?= $nlCurrent === $nlS ? ' aria-current="page"' : '' ?> href="<?= $nlE(NewsletterModule::url(['section' => 'campaigns', 'status' => $nlS])) ?>"><?= $nlT($nlS === '' ? 'newsletter.admin.filter_all' : 'newsletter.status.' . $nlS) ?></a></li>
    <?php endforeach; ?>
</ul>
<div class="card">
    <?php if (empty($nlData['campaigns'])): ?>
        <div class="card-body text-secondary"><?= $nlT('newsletter.admin.campaigns_empty') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th><?= $nlT('newsletter.admin.col_campaign') ?></th><th><?= $nlT('newsletter.admin.col_status') ?></th><th><?= $nlT('newsletter.admin.col_audience') ?></th><th class="text-end"><?= $nlT('newsletter.admin.col_sent') ?></th><th><?= $nlT('newsletter.admin.col_date') ?></th></tr></thead>
            <tbody>
            <?php foreach ((array) $nlData['campaigns'] as $nlRow): ?>
                <tr>
                    <td><a href="<?= $nlE(NewsletterModule::url(['section' => 'campaign', 'id' => (int) $nlRow['id']])) ?>" class="fw-medium"><?= $nlE($nlRow['name']) ?></a><div class="small text-secondary"><?= $nlE($nlRow['subject']) ?></div></td>
                    <td><span class="badge <?= $nlE($nlStatusBadge[$nlRow['status']] ?? '') ?>"><?= $nlT('newsletter.status.' . $nlRow['status']) ?></span><?php if ((int) $nlRow['is_public'] === 1): ?> <span class="badge bg-azure-lt"><?= $nlT('newsletter.admin.public') ?></span><?php endif; ?></td>
                    <td class="text-secondary"><?= $nlE($nlRow['segment_slug'] !== '' ? $nlRow['segment_slug'] : __('newsletter.admin.all_segments')) ?><?= $nlRow['locale'] !== '' ? ' · ' . $nlE(strtoupper($nlRow['locale'])) : '' ?></td>
                    <td class="text-end"><?= (int) $nlRow['sent_count'] ?> / <?= (int) $nlRow['recipient_count'] ?><?php if ((int) $nlRow['failed_count'] > 0): ?><div class="small text-danger"><?= $nlT('newsletter.admin.failed_count', (int) $nlRow['failed_count']) ?></div><?php endif; ?></td>
                    <td class="text-secondary text-nowrap"><?= $nlE($nlDate($nlRow['sent_at'] ?? ($nlRow['scheduled_at'] ?? $nlRow['updated_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
