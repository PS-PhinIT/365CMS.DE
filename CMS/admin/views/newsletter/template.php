<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Vorlage bearbeiten.
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var string $nlCsrf
 */

$nlTpl = (array) ($nlData['template'] ?? []);
$nlNew = !empty($nlData['is_new']);
$nlId = (int) ($nlTpl['id'] ?? 0);
$nlAction = $nlE(NewsletterModule::url(['section' => 'template', 'id' => $nlNew ? null : $nlId]));
?>
<form method="post" action="<?= $nlAction ?>" class="card mb-4">
    <?= $nlCsrf ?>
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="id" value="<?= $nlId ?>">
    <div class="card-header"><h3 class="card-title"><?= $nlT($nlNew ? 'newsletter.admin.new_template' : 'newsletter.admin.edit_template') ?></h3></div>
    <div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label required" for="nlTName"><?= $nlT('newsletter.admin.field_name') ?></label><input type="text" class="form-control" id="nlTName" name="name" maxlength="180" required value="<?= $nlE($nlTpl['name'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label" for="nlTStatus"><?= $nlT('newsletter.admin.col_status') ?></label>
            <select class="form-select" id="nlTStatus" name="status"><option value="active"<?= ($nlTpl['status'] ?? '') === 'active' ? ' selected' : '' ?>><?= $nlT('newsletter.template_status.active') ?></option><option value="draft"<?= ($nlTpl['status'] ?? '') === 'draft' ? ' selected' : '' ?>><?= $nlT('newsletter.template_status.draft') ?></option></select></div>
        <div class="col-12"><label class="form-label required" for="nlTSubject"><?= $nlT('newsletter.admin.field_subject') ?></label><input type="text" class="form-control" id="nlTSubject" name="subject" maxlength="255" required value="<?= $nlE($nlTpl['subject'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label" for="nlTHtml"><?= $nlT('newsletter.admin.field_content_html') ?></label><textarea class="form-control font-monospace" id="nlTHtml" name="content_html" rows="14" aria-describedby="nlTHint"><?= $nlE($nlTpl['content_html'] ?? '') ?></textarea><div class="form-hint" id="nlTHint"><?= $nlT('newsletter.admin.content_hint') ?></div></div>
        <div class="col-12"><label class="form-label" for="nlTText"><?= $nlT('newsletter.admin.field_content_text') ?></label><textarea class="form-control font-monospace" id="nlTText" name="content_text" rows="5"><?= $nlE($nlTpl['content_text'] ?? '') ?></textarea></div>
    </div>
    <div class="card-footer d-flex gap-2">
        <a class="btn" href="<?= $nlE(NewsletterModule::url(['section' => 'templates'])) ?>"><?= $nlT('newsletter.admin.back') ?></a>
        <button type="submit" class="btn btn-primary ms-auto"><?= $nlT('newsletter.admin.save') ?></button>
    </div>
</form>
<?php if (!$nlNew): ?>
    <form method="post" action="<?= $nlAction ?>" data-confirm-title="<?= $nlT('newsletter.admin.delete_template') ?>" data-confirm-message="<?= $nlT('newsletter.admin.delete_template_confirm') ?>" data-confirm-text="<?= $nlT('newsletter.admin.action_delete') ?>" data-confirm-class="btn-danger">
        <?= $nlCsrf ?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="id" value="<?= $nlId ?>">
        <button type="submit" class="btn btn-outline-danger"><?= $nlT('newsletter.admin.delete_template') ?></button>
    </form>
<?php endif; ?>
