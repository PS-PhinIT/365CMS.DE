<?php
declare(strict_types=1);
if (!defined('ABSPATH')) exit;

/**
 * Karte „Erklärung zur Barrierefreiheit“ der Legal Sites (eingebunden aus views/legal/sites.php).
 *
 * @var array $a11y Daten aus LegalSitesModule::getAccessibilityData()
 * @var string $legalSitesAdminBaseUrl
 * @var string|null $csrfToken
 */

$a11yFields = is_array($a11y['fields'] ?? null) ? $a11y['fields'] : [];
$a11yErrors = is_array($a11y['errors'] ?? null) ? $a11y['errors'] : [];
$a11yReview = is_array($a11y['review'] ?? null) ? $a11y['review'] : ['state' => 'missing', 'due' => null];
$a11yFooter = is_array($a11y['footer_location'] ?? null) ? $a11y['footer_location'] : null;
$a11yEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$a11yValue = static fn (string $key): string => htmlspecialchars((string) ($a11yFields[$key] ?? ''), ENT_QUOTES, 'UTF-8');
$a11yDue = '';
if (!empty($a11yReview['due'])) {
    $a11yDueDate = \CMS\Services\Legal\AccessibilityStatementService::parseDate((string) $a11yReview['due']);
    $a11yDue = $a11yDueDate !== null ? \CMS\Services\Legal\AccessibilityStatementService::formatDate($a11yDueDate) : '';
}
$a11yReviewBadge = match ((string) ($a11yReview['state'] ?? 'missing')) {
    'ok' => 'bg-green-lt',
    'due' => 'bg-yellow-lt',
    default => 'bg-red-lt',
};
$a11yAction = $a11yEsc($legalSitesAdminBaseUrl);
$a11yCsrf = $a11yEsc($csrfToken ?? '');
?>
<div class="card mb-4 admin-content-card" id="legal-accessibility">
    <div class="card-header d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <div>
            <h3 class="card-title mb-1"><?php echo $a11yEsc(__('a11y.card.title')); ?></h3>
            <div class="text-secondary small"><?php echo $a11yEsc(__('a11y.card.subtitle')); ?></div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge <?php echo !empty($a11y['complete']) ? 'bg-green-lt' : 'bg-yellow-lt'; ?>"><?php echo $a11yEsc(__(!empty($a11y['complete']) ? 'a11y.card.complete' : 'a11y.card.incomplete')); ?></span>
            <?php if (!empty($a11y['page_published'])): ?>
                <a class="badge bg-green-lt" href="<?php echo $a11yEsc($a11y['page_url'] ?? ''); ?>" target="_blank" rel="noopener"><?php echo $a11yEsc(__('a11y.card.page_de')); ?></a>
                <a class="badge bg-green-lt" href="<?php echo $a11yEsc($a11y['page_url_en'] ?? ''); ?>" target="_blank" rel="noopener"><?php echo $a11yEsc(__('a11y.card.page_en')); ?></a>
            <?php else: ?>
                <span class="badge bg-secondary-lt"><?php echo $a11yEsc(__('a11y.card.no_page')); ?></span>
            <?php endif; ?>
            <span class="badge <?php echo !empty($a11y['footer_linked']) ? 'bg-green-lt' : 'bg-secondary-lt'; ?>"><?php echo $a11yEsc(__(!empty($a11y['footer_linked']) ? 'a11y.card.footer_linked' : 'a11y.card.footer_unlinked')); ?></span>
        </div>
    </div>
    <div class="card-body">
        <div class="alert alert-warning mb-3" role="note">
            <strong><?php echo $a11yEsc(__('a11y.card.disclaimer_title')); ?></strong>
            <?php echo $a11yEsc(__('a11y.card.disclaimer')); ?>
        </div>

        <?php if ($a11yErrors !== []): ?>
            <div class="alert alert-danger mb-3" role="alert">
                <strong><?php echo $a11yEsc(__('a11y.save.invalid')); ?></strong>
                <ul class="mb-0 mt-1">
                    <?php foreach (\CMS\Services\Legal\AccessibilityStatementService::errorMessages(array_map('strval', $a11yErrors)) as $a11yMessage): ?>
                        <li><?php echo $a11yEsc($a11yMessage); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100 bg-light-subtle">
                    <div class="subheader"><?php echo $a11yEsc(__('a11y.review.title')); ?></div>
                    <div class="fw-bold mb-2">
                        <span class="badge <?php echo $a11yReviewBadge; ?>"><?php echo $a11yEsc(__('a11y.review.state.' . (string) ($a11yReview['state'] ?? 'missing'))); ?></span>
                        <?php if ($a11yDue !== ''): ?>
                            <span class="ms-1"><?php echo $a11yEsc(sprintf(__('a11y.review.due'), $a11yDue)); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="text-secondary small mb-2"><?php echo $a11yEsc(__('a11y.review.hint')); ?></div>
                    <form method="post" action="<?php echo $a11yAction; ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?php echo $a11yCsrf; ?>">
                        <input type="hidden" name="action" value="review_accessibility">
                        <button type="submit" class="btn btn-outline-primary btn-sm"><?php echo $a11yEsc(__('a11y.review.confirm')); ?></button>
                    </form>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100 bg-light-subtle">
                    <div class="subheader"><?php echo $a11yEsc(__('a11y.footer.title')); ?></div>
                    <div class="fw-bold mb-2"><?php echo $a11yEsc($a11yFooter !== null ? sprintf(__('a11y.footer.location'), $a11yFooter['label'] ?? $a11yFooter['slug'] ?? '') : __('a11y.footer.no_location')); ?></div>
                    <div class="text-secondary small"><?php echo $a11yEsc(__('a11y.footer.hint')); ?></div>
                </div>
            </div>
        </div>

        <form method="post" action="<?php echo $a11yAction; ?>" data-legal-bulk-save-part="accessibility">
            <input type="hidden" name="csrf_token" value="<?php echo $a11yCsrf; ?>">
            <input type="hidden" name="action" value="save_accessibility">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="a11y-status"><?php echo $a11yEsc(__('a11y.field.status')); ?></label>
                    <select class="form-select" id="a11y-status" name="a11y_status">
                        <?php foreach (\CMS\Services\Legal\AccessibilityStatementService::STATUSES as $a11yStatus): ?>
                            <option value="<?php echo $a11yEsc($a11yStatus); ?>" <?php echo ($a11yFields['status'] ?? '') === $a11yStatus ? 'selected' : ''; ?>><?php echo $a11yEsc(__('a11y.status.' . $a11yStatus)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="a11y-standard"><?php echo $a11yEsc(__('a11y.field.standard')); ?></label>
                    <input type="text" class="form-control" id="a11y-standard" name="a11y_standard" maxlength="300" value="<?php echo $a11yValue('standard'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="a11y-legal-basis"><?php echo $a11yEsc(__('a11y.field.legal_basis')); ?></label>
                    <input type="text" class="form-control" id="a11y-legal-basis" name="a11y_legal_basis" maxlength="300" value="<?php echo $a11yValue('legal_basis'); ?>">
                    <small class="form-hint"><?php echo $a11yEsc(__('a11y.field.legal_basis_hint')); ?></small>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="a11y-issues-de"><?php echo $a11yEsc(__('a11y.field.issues_de')); ?></label>
                    <textarea class="form-control" id="a11y-issues-de" name="a11y_issues_de" rows="5" maxlength="4000"><?php echo $a11yValue('issues_de'); ?></textarea>
                    <small class="form-hint"><?php echo $a11yEsc(__('a11y.field.issues_hint')); ?></small>
                </div>
                <div class="col-lg-6">
                    <label class="form-label" for="a11y-issues-en"><?php echo $a11yEsc(__('a11y.field.issues_en')); ?></label>
                    <textarea class="form-control" id="a11y-issues-en" name="a11y_issues_en" rows="5" maxlength="4000"><?php echo $a11yValue('issues_en'); ?></textarea>
                    <small class="form-hint"><?php echo $a11yEsc(__('a11y.field.issues_en_hint')); ?></small>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="a11y-created-at"><?php echo $a11yEsc(__('a11y.field.created_at')); ?></label>
                    <input type="date" class="form-control" id="a11y-created-at" name="a11y_created_at" value="<?php echo $a11yValue('created_at'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="a11y-reviewed-at"><?php echo $a11yEsc(__('a11y.field.reviewed_at')); ?></label>
                    <input type="date" class="form-control" id="a11y-reviewed-at" name="a11y_reviewed_at" value="<?php echo $a11yValue('reviewed_at'); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="a11y-review-method"><?php echo $a11yEsc(__('a11y.field.review_method')); ?></label>
                    <select class="form-select" id="a11y-review-method" name="a11y_review_method">
                        <?php foreach (\CMS\Services\Legal\AccessibilityStatementService::REVIEW_METHODS as $a11yMethod): ?>
                            <option value="<?php echo $a11yEsc($a11yMethod); ?>" <?php echo ($a11yFields['review_method'] ?? '') === $a11yMethod ? 'selected' : ''; ?>><?php echo $a11yEsc(__('a11y.method.' . $a11yMethod)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12"><div class="subheader mt-2"><?php echo $a11yEsc(__('a11y.field.feedback')); ?></div></div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-feedback-name"><?php echo $a11yEsc(__('a11y.field.name')); ?></label>
                    <input type="text" class="form-control" id="a11y-feedback-name" name="a11y_feedback_name" maxlength="300" value="<?php echo $a11yValue('feedback_name'); ?>">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-feedback-email"><?php echo $a11yEsc(__('a11y.field.email')); ?></label>
                    <input type="email" class="form-control" id="a11y-feedback-email" name="a11y_feedback_email" maxlength="300" value="<?php echo $a11yValue('feedback_email'); ?>">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-feedback-phone"><?php echo $a11yEsc(__('a11y.field.phone')); ?></label>
                    <input type="text" class="form-control" id="a11y-feedback-phone" name="a11y_feedback_phone" maxlength="300" value="<?php echo $a11yValue('feedback_phone'); ?>">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-feedback-url"><?php echo $a11yEsc(__('a11y.field.url')); ?></label>
                    <input type="url" class="form-control" id="a11y-feedback-url" name="a11y_feedback_url" maxlength="300" value="<?php echo $a11yValue('feedback_url'); ?>">
                </div>
                <div class="col-12"><small class="form-hint"><?php echo $a11yEsc(__('a11y.field.feedback_hint')); ?></small></div>

                <div class="col-12"><div class="subheader mt-2"><?php echo $a11yEsc(__('a11y.field.enforcement')); ?></div></div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-enforcement-name"><?php echo $a11yEsc(__('a11y.field.name')); ?></label>
                    <input type="text" class="form-control" id="a11y-enforcement-name" name="a11y_enforcement_name" maxlength="300" value="<?php echo $a11yValue('enforcement_name'); ?>">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-enforcement-address"><?php echo $a11yEsc(__('a11y.field.address')); ?></label>
                    <textarea class="form-control" id="a11y-enforcement-address" name="a11y_enforcement_address" rows="2" maxlength="4000"><?php echo $a11yValue('enforcement_address'); ?></textarea>
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-enforcement-email"><?php echo $a11yEsc(__('a11y.field.email')); ?></label>
                    <input type="email" class="form-control" id="a11y-enforcement-email" name="a11y_enforcement_email" maxlength="300" value="<?php echo $a11yValue('enforcement_email'); ?>">
                </div>
                <div class="col-md-6 col-xl-3">
                    <label class="form-label" for="a11y-enforcement-url"><?php echo $a11yEsc(__('a11y.field.url')); ?></label>
                    <input type="url" class="form-control" id="a11y-enforcement-url" name="a11y_enforcement_url" maxlength="300" value="<?php echo $a11yValue('enforcement_url'); ?>">
                </div>
                <div class="col-12"><small class="form-hint"><?php echo $a11yEsc(__('a11y.field.enforcement_hint')); ?></small></div>

                <div class="col-md-6">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="a11y_footer_link" value="1" <?php echo ($a11yFields['footer_link'] ?? '1') === '1' ? 'checked' : ''; ?>>
                        <span class="form-check-label"><?php echo $a11yEsc(__('a11y.field.footer_link')); ?></span>
                    </label>
                </div>
                <div class="col-md-6">
                    <label class="form-check">
                        <input type="checkbox" class="form-check-input" name="a11y_update_page" value="1">
                        <span class="form-check-label"><?php echo $a11yEsc(__('a11y.field.update_page')); ?></span>
                    </label>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap mt-3">
                <button type="submit" class="btn btn-primary"><?php echo $a11yEsc(__('a11y.card.save')); ?></button>
            </div>
        </form>

        <?php if (!empty($a11y['preview_en'])): ?>
            <details class="mt-4">
                <summary><?php echo $a11yEsc(__('a11y.card.preview_en')); ?></summary>
                <div class="border rounded p-3 mt-2 legal-sites-html-preview"><?php echo (string) $a11y['preview_en']; ?></div>
            </details>
        <?php endif; ?>
    </div>
</div>
