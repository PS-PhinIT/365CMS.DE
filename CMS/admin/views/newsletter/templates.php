<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Vorlagen.
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var callable $nlDate
 * @var array<string, string> $nlStatusBadge
 */
?>
<div class="card">
    <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.tab_templates') ?></h3>
        <div class="card-actions"><a class="btn btn-primary" href="<?= $nlE(NewsletterModule::url(['section' => 'template'])) ?>"><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.new_template') ?></a></div></div>
    <?php if (empty($nlData['templates'])): ?>
        <div class="card-body text-secondary"><?= $nlT('newsletter.admin.templates_empty') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr><th><?= $nlT('newsletter.admin.field_name') ?></th><th><?= $nlT('newsletter.admin.field_subject') ?></th><th><?= $nlT('newsletter.admin.col_status') ?></th><th><?= $nlT('newsletter.admin.col_date') ?></th></tr></thead>
            <tbody>
            <?php foreach ((array) $nlData['templates'] as $nlRow): ?>
                <tr>
                    <td><a href="<?= $nlE(NewsletterModule::url(['section' => 'template', 'id' => (int) $nlRow['id']])) ?>"><?= $nlE($nlRow['name']) ?></a></td>
                    <td class="text-secondary"><?= $nlE($nlRow['subject']) ?></td>
                    <td><span class="badge <?= $nlRow['status'] === 'active' ? 'bg-green-lt' : 'bg-secondary-lt' ?>"><?= $nlT('newsletter.template_status.' . $nlRow['status']) ?></span></td>
                    <td class="text-secondary text-nowrap"><?= $nlE($nlDate($nlRow['updated_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>
