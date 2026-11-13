<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Abonnent anlegen/bearbeiten, Einwilligungsnachweis, Versandverlauf.
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var callable $nlDate
 * @var string $nlCsrf
 * @var array<string, string> $nlStatusBadge
 */

$nlS = (array) ($nlData['subscriber'] ?? []);
$nlNew = !empty($nlData['is_new']);
$nlId = (int) ($nlS['id'] ?? 0);
$nlAction = $nlE(NewsletterModule::url(['section' => 'subscriber', 'id' => $nlNew ? null : $nlId]));
?>
<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" action="<?= $nlAction ?>" class="card">
            <?= $nlCsrf ?>
            <input type="hidden" name="action" value="save_subscriber">
            <input type="hidden" name="id" value="<?= $nlId ?>">
            <div class="card-header"><h3 class="card-title"><?= $nlT($nlNew ? 'newsletter.admin.new_subscriber' : 'newsletter.admin.edit_subscriber') ?></h3></div>
            <div class="card-body row g-3">
                <div class="col-12"><label class="form-label required" for="nlEmail"><?= $nlT('newsletter.admin.col_email') ?></label><input type="email" class="form-control" id="nlEmail" name="email" maxlength="190" required value="<?= $nlE($nlS['email'] ?? '') ?>"></div>
                <div class="col-sm-6"><label class="form-label" for="nlFirst"><?= $nlT('newsletter.admin.field_first_name') ?></label><input type="text" class="form-control" id="nlFirst" name="first_name" maxlength="120" value="<?= $nlE($nlS['first_name'] ?? '') ?>"></div>
                <div class="col-sm-6"><label class="form-label" for="nlLast"><?= $nlT('newsletter.admin.field_last_name') ?></label><input type="text" class="form-control" id="nlLast" name="last_name" maxlength="120" value="<?= $nlE($nlS['last_name'] ?? '') ?>"></div>
                <div class="col-sm-4"><label class="form-label" for="nlStatusSel"><?= $nlT('newsletter.admin.col_status') ?></label>
                    <select class="form-select" id="nlStatusSel" name="status"><?php foreach (\CMS\Services\Newsletter\NewsletterRepository::SUBSCRIBER_STATUSES as $nlSt): ?><option value="<?= $nlE($nlSt) ?>"<?= ($nlS['status'] ?? '') === $nlSt ? ' selected' : '' ?>><?= $nlT('newsletter.status.' . $nlSt) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-4"><label class="form-label" for="nlSeg"><?= $nlT('newsletter.admin.col_segment') ?></label>
                    <?php $nlSegCurrent = (string) ($nlS['segment_slug'] ?? ''); ?>
                    <select class="form-select" id="nlSeg" name="segment_slug"><?php if (!$nlNew && $nlSegCurrent !== '' && !isset($nlData['segments'][$nlSegCurrent])): /* Verwaistes Thema erhalten statt still auf das erste umzuhängen. */ ?><option value="<?= $nlE($nlSegCurrent) ?>" selected><?= $nlT('newsletter.admin.segment_undefined', $nlSegCurrent) ?></option><?php endif; ?><?php foreach ((array) $nlData['segments'] as $nlSlug => $nlL): ?><option value="<?= $nlE($nlSlug) ?>"<?= ($nlS['segment_slug'] ?? '') === $nlSlug ? ' selected' : '' ?>><?= $nlE($nlL['de']) ?></option><?php endforeach; ?></select></div>
                <div class="col-sm-4"><label class="form-label" for="nlLoc"><?= $nlT('newsletter.admin.field_language') ?></label>
                    <select class="form-select" id="nlLoc" name="locale"><option value="de"<?= ($nlS['locale'] ?? 'de') === 'de' ? ' selected' : '' ?>>Deutsch</option><option value="en"<?= ($nlS['locale'] ?? '') === 'en' ? ' selected' : '' ?>>English</option></select></div>
                <?php if ($nlNew): ?>
                    <div class="col-12"><label class="form-check"><input class="form-check-input" type="checkbox" name="send_confirmation" value="1"><span class="form-check-label"><?= $nlT('newsletter.admin.field_send_confirmation') ?></span></label>
                        <div class="form-hint"><?= $nlT('newsletter.admin.field_admin_consent_hint') ?></div></div>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2">
                <a class="btn" href="<?= $nlE(NewsletterModule::url(['section' => 'subscribers'])) ?>"><?= $nlT('newsletter.admin.back') ?></a>
                <button type="submit" class="btn btn-primary ms-auto"><?= $nlT('newsletter.admin.save') ?></button>
            </div>
        </form>
    </div>
    <?php if (!$nlNew): ?>
        <div class="col-lg-5">
            <div class="card mb-4">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.consent_title') ?></h3></div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-5 fw-normal text-secondary"><?= $nlT('newsletter.admin.col_source') ?></dt><dd class="col-7"><?= $nlT('newsletter.source.' . (in_array($nlS['source'] ?? '', \CMS\Services\Newsletter\NewsletterRepository::SOURCES, true) ? $nlS['source'] : 'admin')) ?></dd>
                        <dt class="col-5 fw-normal text-secondary"><?= $nlT('newsletter.admin.created_at') ?></dt><dd class="col-7"><?= $nlE($nlDate($nlS['created_at'] ?? '')) ?></dd>
                        <dt class="col-5 fw-normal text-secondary"><?= $nlT('newsletter.admin.consent_at') ?></dt><dd class="col-7"><?= $nlE($nlDate($nlS['consent_at'] ?? '')) ?></dd>
                        <dt class="col-5 fw-normal text-secondary"><?= $nlT('newsletter.admin.confirmed_at') ?></dt><dd class="col-7"><?= $nlE($nlDate($nlS['confirmed_at'] ?? '')) ?></dd>
                        <dt class="col-5 fw-normal text-secondary"><?= $nlT('newsletter.admin.unsubscribed_at') ?></dt><dd class="col-7"><?= $nlE($nlDate($nlS['unsubscribed_at'] ?? '')) ?></dd>
                        <dt class="col-5 fw-normal text-secondary"><?= $nlT('newsletter.admin.last_sent_at') ?></dt><dd class="col-7"><?= $nlE($nlDate($nlS['last_sent_at'] ?? '')) ?></dd>
                    </dl>
                    <?php if ((string) ($nlS['consent_text'] ?? '') !== ''): ?><blockquote class="small text-secondary mt-3 mb-0">„<?= $nlE($nlS['consent_text']) ?>“</blockquote><?php endif; ?>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2">
                    <?php if (($nlS['status'] ?? '') === 'pending'): ?>
                        <form method="post" action="<?= $nlAction ?>"><?= $nlCsrf ?><input type="hidden" name="action" value="resend_confirmation"><input type="hidden" name="id" value="<?= $nlId ?>"><button type="submit" class="btn btn-sm"><?= $nlT('newsletter.admin.resend_confirmation') ?></button></form>
                    <?php endif; ?>
                    <form method="post" action="<?= $nlAction ?>" class="ms-auto" data-confirm-title="<?= $nlT('newsletter.admin.delete_subscriber') ?>" data-confirm-message="<?= $nlE(sprintf((string) __('newsletter.admin.delete_subscriber_confirm'), (string) $nlS['email'])) ?>" data-confirm-text="<?= $nlT('newsletter.admin.action_delete') ?>" data-confirm-class="btn-danger">
                        <?= $nlCsrf ?><input type="hidden" name="action" value="delete_subscriber"><input type="hidden" name="id" value="<?= $nlId ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><?= $nlT('newsletter.admin.delete_subscriber') ?></button>
                    </form>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.history_title') ?></h3></div>
                <?php if (empty($nlData['history'])): ?>
                    <div class="card-body text-secondary"><?= $nlT('newsletter.admin.history_empty') ?></div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ((array) $nlData['history'] as $nlH): ?>
                            <div class="list-group-item small d-flex justify-content-between gap-2"><span class="text-truncate"><?= $nlE($nlH['subject'] ?? '–') ?></span><span class="text-secondary text-nowrap"><?= $nlT('newsletter.send_status.' . ($nlH['status'] ?? 'queued')) ?> · <?= $nlE($nlDate($nlH['sent_at'] ?? '')) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
