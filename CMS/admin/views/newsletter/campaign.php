<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Kampagne: Inhalt und Zielgruppe bearbeiten, Vorschau, Testversand, Senden/Planen, Fortschritt.
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var callable $nlDate
 * @var string $nlCsrf
 * @var array<string, string> $nlStatusBadge
 */

$nlC = (array) ($nlData['campaign'] ?? []);
$nlNew = !empty($nlData['is_new']);
$nlId = (int) ($nlC['id'] ?? 0);
$nlStatus = (string) ($nlC['status'] ?? 'draft');
$nlEditable = in_array($nlStatus, ['draft', 'scheduled'], true);
$nlCounts = (array) ($nlData['counts'] ?? []);
$nlAction = $nlE(NewsletterModule::url(['section' => 'campaign', 'id' => $nlNew ? null : $nlId]));
$nlHidden = $nlCsrf . '<input type="hidden" name="id" value="' . $nlId . '">';
$nlRo = $nlEditable ? '' : ' readonly';
$nlDis = $nlEditable ? '' : ' disabled';
?>
<?php if (!$nlNew): ?>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <span class="badge <?= $nlE($nlStatusBadge[$nlStatus] ?? '') ?>"><?= $nlT('newsletter.status.' . $nlStatus) ?></span>
        <?php if ($nlStatus === 'scheduled'): ?><span class="text-secondary"><?= $nlT('newsletter.admin.scheduled_for', $nlDate($nlC['scheduled_at'] ?? '')) ?></span><?php endif; ?>
        <?php if (in_array($nlStatus, ['sending', 'sent', 'cancelled'], true)): ?>
            <span class="text-secondary"><?= $nlT('newsletter.admin.progress', (int) ($nlCounts['sent'] ?? 0), (int) ($nlC['recipient_count'] ?? 0), (int) ($nlCounts['failed'] ?? 0), (int) ($nlCounts['queued'] ?? 0)) ?></span>
        <?php endif; ?>
        <?php if ($nlStatus === 'sending'): ?><div class="progress progress-sm flex-fill admin-newsletter-progress" role="progressbar" aria-valuenow="<?= (int) ($nlCounts['sent'] ?? 0) ?>" aria-valuemin="0" aria-valuemax="<?= max(1, (int) $nlC['recipient_count']) ?>"><div class="progress-bar" data-nl-progress="<?= (int) round(100 * (int) ($nlCounts['sent'] ?? 0) / max(1, (int) $nlC['recipient_count'])) ?>"></div></div><?php endif; ?>
    </div>
<?php endif; ?>
<?php if (empty($nlData['sender_ok'])): ?>
    <div class="alert alert-warning" role="alert"><?= $nlT('newsletter.admin.sender_missing') ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-7">
        <form method="post" action="<?= $nlAction ?>" class="card" id="nlCampaignForm">
            <?= $nlHidden ?>
            <input type="hidden" name="action" value="save_campaign">
            <div class="card-header"><h3 class="card-title"><?= $nlT($nlNew ? 'newsletter.admin.new_campaign' : 'newsletter.admin.edit_campaign') ?></h3></div>
            <div class="card-body row g-3">
                <?php if (!$nlEditable): ?><div class="col-12"><div class="alert alert-info mb-0"><?= $nlT('newsletter.admin.campaign_locked') ?></div></div><?php endif; ?>
                <div class="col-md-6"><label class="form-label required" for="nlName"><?= $nlT('newsletter.admin.field_name') ?></label><input type="text" class="form-control" id="nlName" name="name" maxlength="180" required value="<?= $nlE($nlC['name'] ?? '') ?>"<?= $nlRo ?>></div>
                <div class="col-md-6"><label class="form-label" for="nlTpl"><?= $nlT('newsletter.admin.field_template') ?></label>
                    <select class="form-select" id="nlTpl" name="template_id"<?= $nlDis ?>><option value="0"><?= $nlT('newsletter.admin.no_template') ?></option>
                        <?php foreach ((array) $nlData['templates'] as $nlTplRow): ?><option value="<?= (int) $nlTplRow['id'] ?>"<?= (int) ($nlC['template_id'] ?? 0) === (int) $nlTplRow['id'] ? ' selected' : '' ?>><?= $nlE($nlTplRow['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="col-12"><label class="form-label required" for="nlSubject"><?= $nlT('newsletter.admin.field_subject') ?></label><input type="text" class="form-control" id="nlSubject" name="subject" maxlength="255" required value="<?= $nlE($nlC['subject'] ?? '') ?>"<?= $nlRo ?>></div>
                <div class="col-12"><label class="form-label" for="nlPreview"><?= $nlT('newsletter.admin.field_preview_text') ?></label><input type="text" class="form-control" id="nlPreview" name="preview_text" maxlength="255" value="<?= $nlE($nlC['preview_text'] ?? '') ?>"<?= $nlRo ?> aria-describedby="nlPreviewHint"><div class="form-hint" id="nlPreviewHint"><?= $nlT('newsletter.admin.field_preview_text_hint') ?></div></div>
                <div class="col-12"><label class="form-label" for="nlHtml"><?= $nlT('newsletter.admin.field_content_html') ?></label><textarea class="form-control font-monospace" id="nlHtml" name="content_html" rows="12"<?= $nlRo ?> aria-describedby="nlHtmlHint"><?= $nlE($nlC['content_html'] ?? '') ?></textarea><div class="form-hint" id="nlHtmlHint"><?= $nlT('newsletter.admin.content_hint') ?></div></div>
                <div class="col-12"><label class="form-label" for="nlText"><?= $nlT('newsletter.admin.field_content_text') ?></label><textarea class="form-control font-monospace" id="nlText" name="content_text" rows="4"<?= $nlRo ?>><?= $nlE($nlC['content_text'] ?? '') ?></textarea></div>
                <div class="col-md-6"><label class="form-label" for="nlSeg"><?= $nlT('newsletter.admin.col_segment') ?></label>
                    <select class="form-select" id="nlSeg" name="segment_slug"<?= $nlDis ?>><option value=""><?= $nlT('newsletter.admin.all_segments') ?></option>
                        <?php foreach ((array) $nlData['segments'] as $nlSlug => $nlL): ?><option value="<?= $nlE($nlSlug) ?>"<?= ($nlC['segment_slug'] ?? '') === $nlSlug ? ' selected' : '' ?>><?= $nlE($nlL['de']) ?> (<?= (int) (($nlData['segment_counts'] ?? [])[$nlSlug] ?? 0) ?>)</option><?php endforeach; ?>
                    </select></div>
                <div class="col-md-6"><label class="form-label" for="nlLoc"><?= $nlT('newsletter.admin.field_language') ?></label>
                    <select class="form-select" id="nlLoc" name="locale"<?= $nlDis ?>><option value=""><?= $nlT('newsletter.admin.all_languages') ?></option><option value="de"<?= ($nlC['locale'] ?? '') === 'de' ? ' selected' : '' ?>>Deutsch</option><option value="en"<?= ($nlC['locale'] ?? '') === 'en' ? ' selected' : '' ?>>English</option></select></div>
                <div class="col-12"><label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_public" value="1"<?= (int) ($nlC['is_public'] ?? 0) === 1 ? ' checked' : '' ?>><span class="form-check-label"><?= $nlT('newsletter.admin.field_public') ?></span></label></div>
            </div>
            <div class="card-footer d-flex gap-2">
                <a class="btn" href="<?= $nlE(NewsletterModule::url(['section' => 'campaigns'])) ?>"><?= $nlT('newsletter.admin.back') ?></a>
                <button type="submit" class="btn btn-primary ms-auto"><?= $nlT('newsletter.admin.save') ?></button>
            </div>
        </form>
    </div>

    <div class="col-xl-5">
        <?php if (!$nlNew): ?>
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.send_title') ?></h3></div>
                <div class="card-body">
                    <p class="text-secondary"><?= $nlT('newsletter.admin.recipients_now', (int) ($nlData['recipients'] ?? 0)) ?></p>
                    <form method="post" action="<?= $nlAction ?>" class="mb-3"><?= $nlHidden ?><input type="hidden" name="action" value="test_campaign">
                        <label class="form-label" for="nlTestMail"><?= $nlT('newsletter.admin.test_label') ?></label>
                        <div class="input-group"><input type="email" class="form-control" id="nlTestMail" name="test_email" maxlength="190" placeholder="<?= $nlT('newsletter.admin.test_placeholder') ?>"><button type="submit" class="btn"><?= $nlT('newsletter.admin.test_button') ?></button></div>
                    </form>
                    <?php if (in_array($nlStatus, ['draft', 'scheduled'], true)): ?>
                        <form method="post" action="<?= $nlAction ?>" class="mb-3" data-confirm-title="<?= $nlT('newsletter.admin.send_now') ?>" data-confirm-message="<?= $nlT('newsletter.admin.send_confirm', (int) ($nlData['recipients'] ?? 0)) ?>" data-confirm-text="<?= $nlT('newsletter.admin.send_now') ?>" data-confirm-class="btn-primary">
                            <?= $nlHidden ?><input type="hidden" name="action" value="send_campaign">
                            <button type="submit" class="btn btn-primary w-100"<?= (int) ($nlData['recipients'] ?? 0) === 0 ? ' disabled' : '' ?>><i class="ti ti-send me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.send_now') ?></button>
                        </form>
                        <form method="post" action="<?= $nlAction ?>"><?= $nlHidden ?><input type="hidden" name="action" value="schedule_campaign">
                            <label class="form-label" for="nlWhen"><?= $nlT('newsletter.admin.schedule_label') ?></label>
                            <div class="input-group"><input type="datetime-local" class="form-control" id="nlWhen" name="scheduled_at" required value="<?= $nlE(!empty($nlC['scheduled_at']) ? date('Y-m-d\TH:i', (int) strtotime((string) $nlC['scheduled_at'])) : '') ?>"><button type="submit" class="btn"><?= $nlT('newsletter.admin.schedule_button') ?></button></div>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($nlStatus, ['scheduled', 'sending'], true)): ?>
                        <form method="post" action="<?= $nlAction ?>" class="mt-3" data-confirm-title="<?= $nlT('newsletter.admin.cancel_campaign') ?>" data-confirm-message="<?= $nlT('newsletter.admin.cancel_confirm') ?>" data-confirm-text="<?= $nlT('newsletter.admin.cancel_campaign') ?>" data-confirm-class="btn-danger">
                            <?= $nlHidden ?><input type="hidden" name="action" value="cancel_campaign"><button type="submit" class="btn btn-outline-danger w-100"><?= $nlT('newsletter.admin.cancel_campaign') ?></button></form>
                    <?php endif; ?>
                    <?php if ((int) ($nlCounts['failed'] ?? 0) > 0 && in_array($nlStatus, ['sending', 'sent'], true)): ?>
                        <form method="post" action="<?= $nlAction ?>" class="mt-3"><?= $nlHidden ?><input type="hidden" name="action" value="retry_failed"><button type="submit" class="btn w-100"><?= $nlT('newsletter.admin.retry_failed', (int) $nlCounts['failed']) ?></button></form>
                    <?php endif; ?>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    <form method="post" action="<?= $nlAction ?>"><?= $nlHidden ?><input type="hidden" name="action" value="duplicate_campaign"><button type="submit" class="btn btn-sm"><i class="ti ti-copy me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.duplicate') ?></button></form>
                    <?php if ($nlStatus === 'sent' && (int) ($nlC['is_public'] ?? 0) === 1): ?><a class="btn btn-sm" href="/newsletter/issue/<?= $nlId ?>" target="_blank" rel="noopener"><?= $nlT('newsletter.admin.web_version') ?></a><?php endif; ?>
                    <?php if ($nlStatus !== 'sending'): ?>
                        <form method="post" action="<?= $nlAction ?>" class="ms-auto" data-confirm-title="<?= $nlT('newsletter.admin.delete_campaign') ?>" data-confirm-message="<?= $nlT('newsletter.admin.delete_campaign_confirm') ?>" data-confirm-text="<?= $nlT('newsletter.admin.action_delete') ?>" data-confirm-class="btn-danger">
                            <?= $nlHidden ?><input type="hidden" name="action" value="delete_campaign"><button type="submit" class="btn btn-sm btn-outline-danger"><?= $nlT('newsletter.admin.action_delete') ?></button></form>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($nlData['failed'])): ?>
                <div class="card mb-4">
                    <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.failed_title') ?></h3></div>
                    <div class="list-group list-group-flush">
                        <?php foreach ((array) $nlData['failed'] as $nlF): ?><div class="list-group-item small"><div><?= $nlE($nlF['email'] ?? '–') ?></div><div class="text-danger text-break"><?= $nlE(mb_substr((string) ($nlF['last_error'] ?? ''), 0, 200)) ?></div></div><?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.preview_title') ?></h3></div>
                <div class="card-body p-0"><iframe class="admin-newsletter-preview" title="<?= $nlT('newsletter.admin.preview_title') ?>" sandbox="" src="<?= $nlE(NewsletterModule::url(['preview' => $nlId])) ?>" loading="lazy"></iframe></div>
            </div>
        <?php else: ?>
            <div class="card"><div class="card-body text-secondary"><?= $nlT('newsletter.admin.save_first') ?></div></div>
        <?php endif; ?>
    </div>
</div>
