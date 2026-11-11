<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $data */
/** @var string $csrfToken */

$retentionRules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
$retentionSummary = is_array($data['summary'] ?? null) ? $data['summary'] : [];
$retentionLastFullRun = is_array($data['last_full_run'] ?? null) ? $data['last_full_run'] : null;
$retentionCommentIps = is_array($data['comment_ips'] ?? null) ? $data['comment_ips'] : ['days' => 0, 'mode' => 'anonymize'];
$retentionCommentIpAnchor = (string) ($data['comment_ip_anchor'] ?? 'retention-comment-ips');
$retentionMaxDays = (int) ($data['comment_ip_max_days'] ?? 3650);

$retentionEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$retentionFormatDate = static function (mixed $value): string {
    if (!is_string($value) || $value === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d.m.Y H:i');
    } catch (\Throwable) {
        return '';
    }
};
$retentionFormatDays = static function (int $days): string {
    if ($days <= 0) {
        return __('retention.never');
    }

    return $days === 1 ? __('retention.days_one') : sprintf(__('retention.days'), $days);
};
?>

<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle"><?php echo $retentionEsc(__('retention.pretitle')); ?></div>
                <h2 class="page-title mb-1"><?php echo $retentionEsc(__('retention.page_title')); ?></h2>
                <div class="content-listing-header__meta">
                    <span><?php echo $retentionEsc(sprintf(__('retention.meta.rules'), (int) ($retentionSummary['total'] ?? 0))); ?></span>
                    <span><?php echo $retentionEsc(sprintf(__('retention.meta.active'), (int) ($retentionSummary['active'] ?? 0))); ?></span>
                    <span><?php echo $retentionEsc(sprintf(__('retention.meta.due'), (int) ($retentionSummary['due'] ?? 0))); ?></span>
                </div>
            </div>
            <div class="admin-section-toolbar__actions">
                <form method="post" action="/admin/data-retention"
                      data-confirm-title="<?php echo $retentionEsc(__('retention.run_all_confirm_title')); ?>"
                      data-confirm-message="<?php echo $retentionEsc(__('retention.run_all_confirm')); ?>"
                      data-confirm-text="<?php echo $retentionEsc(__('retention.run_all')); ?>"
                      data-confirm-class="btn-danger">
                    <input type="hidden" name="csrf_token" value="<?php echo $retentionEsc($csrfToken); ?>">
                    <input type="hidden" name="action" value="run_all">
                    <button type="submit" class="btn btn-outline-danger btn-sm"><?php echo $retentionEsc(__('retention.run_all')); ?></button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="page-body admin-redesign-page admin-data-retention-page">
    <div class="container-xl admin-redesign-shell">
        <?php
        $alertData = $alert ?? [];
        $alertDismissible = false;
        $alertMarginClass = 'mb-4';
        require __DIR__ . '/../partials/flash-alert.php';
        ?>

        <div class="card mb-4">
            <div class="card-body">
                <p class="text-secondary mb-1"><?php echo $retentionEsc(__('retention.intro')); ?></p>
                <p class="text-secondary small mb-0">
                    <?php if ($retentionLastFullRun !== null && $retentionFormatDate($retentionLastFullRun['at'] ?? '') !== ''): ?>
                        <?php echo $retentionEsc(sprintf(
                            __('retention.last_full_run'),
                            $retentionFormatDate($retentionLastFullRun['at'] ?? ''),
                            array_sum(array_map('intval', is_array($retentionLastFullRun['results'] ?? null) ? $retentionLastFullRun['results'] : []))
                        )); ?>
                    <?php else: ?>
                        <?php echo $retentionEsc(__('retention.no_full_run')); ?>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <?php if ($retentionRules !== []): ?>
        <div class="card mb-4">
            <div class="table-responsive">
                <table class="table table-vcenter card-table" data-retention-rules>
                    <thead>
                        <tr>
                            <th><?php echo $retentionEsc(__('retention.col.rule')); ?></th>
                            <th><?php echo $retentionEsc(__('retention.col.period')); ?></th>
                            <th><?php echo $retentionEsc(__('retention.col.action')); ?></th>
                            <th class="text-end"><?php echo $retentionEsc(__('retention.col.dry_run')); ?></th>
                            <th><?php echo $retentionEsc(__('retention.col.last_run')); ?></th>
                            <th class="w-1"><span class="visually-hidden"><?php echo $retentionEsc(__('retention.col.actions')); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($retentionRules as $rule): ?>
                        <?php
                        $ruleKey = (string) ($rule['key'] ?? '');
                        $ruleDays = (int) ($rule['days'] ?? 0);
                        $ruleDue = $rule['due'] ?? null;
                        $ruleLastRun = is_array($rule['last_run'] ?? null) ? $rule['last_run'] : null;
                        $ruleLastRunDate = $ruleLastRun !== null ? $retentionFormatDate($ruleLastRun['at'] ?? '') : '';
                        $ruleSettingsUrl = (string) ($rule['settings_url'] ?? '');
                        ?>
                        <tr data-retention-rule="<?php echo $retentionEsc($ruleKey); ?>">
                            <td>
                                <div class="fw-medium"><?php echo $retentionEsc($rule['label'] ?? $ruleKey); ?></div>
                                <?php if (trim((string) ($rule['description'] ?? '')) !== ''): ?>
                                    <div class="text-secondary small"><?php echo $retentionEsc($rule['description']); ?></div>
                                <?php endif; ?>
                                <div class="small mt-1">
                                    <code><?php echo $retentionEsc($ruleKey); ?></code>
                                    <span class="badge <?php echo !empty($rule['core']) ? 'bg-blue-lt' : 'bg-purple-lt'; ?> ms-1">
                                        <?php echo $retentionEsc(!empty($rule['core']) ? __('retention.badge.core') : __('retention.badge.extension')); ?>
                                    </span>
                                </div>
                            </td>
                            <td class="text-nowrap"><?php echo $retentionEsc($retentionFormatDays($ruleDays)); ?></td>
                            <td class="text-nowrap">
                                <?php echo $retentionEsc(($rule['action'] ?? '') === 'anonymize' ? __('retention.action.anonymize') : __('retention.action.delete')); ?>
                            </td>
                            <td class="text-end text-nowrap" data-retention-due>
                                <?php if ($ruleDue === null): ?>
                                    <span class="badge bg-warning-lt"><?php echo $retentionEsc(__('retention.dry_run_failed')); ?></span>
                                <?php else: ?>
                                    <span class="<?php echo (int) $ruleDue > 0 ? 'fw-bold' : 'text-secondary'; ?>"><?php echo $retentionEsc(sprintf(__('retention.dry_run_count'), (int) $ruleDue)); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap" data-retention-last-run>
                                <?php if ($ruleLastRunDate !== ''): ?>
                                    <div><?php echo $retentionEsc($ruleLastRunDate); ?></div>
                                    <div class="text-secondary small">
                                        <?php echo $retentionEsc(sprintf(__('retention.last_run_count'), (int) ($ruleLastRun['count'] ?? 0))); ?>
                                        <?php if (!empty($ruleLastRun['error'])): ?>
                                            <span class="badge bg-danger-lt ms-1"><?php echo $retentionEsc(__('retention.last_run_error')); ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-secondary"><?php echo $retentionEsc(__('retention.last_run_none')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-nowrap">
                                <div class="d-flex gap-2 justify-content-end">
                                    <?php if ($ruleSettingsUrl !== ''): ?>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo $retentionEsc($ruleSettingsUrl); ?>"><?php echo $retentionEsc(__('retention.settings_link')); ?></a>
                                    <?php endif; ?>
                                    <form method="post" action="/admin/data-retention"
                                          data-confirm-title="<?php echo $retentionEsc(__('retention.run_rule_confirm_title')); ?>"
                                          data-confirm-message="<?php echo $retentionEsc(sprintf(__('retention.run_rule_confirm'), (string) ($rule['label'] ?? $ruleKey))); ?>"
                                          data-confirm-text="<?php echo $retentionEsc(__('retention.run_rule')); ?>"
                                          data-confirm-class="btn-danger">
                                        <input type="hidden" name="csrf_token" value="<?php echo $retentionEsc($csrfToken); ?>">
                                        <input type="hidden" name="action" value="run_rule">
                                        <input type="hidden" name="rule_key" value="<?php echo $retentionEsc($ruleKey); ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"<?php echo $ruleDays > 0 ? '' : ' disabled title="' . $retentionEsc(__('retention.disabled_hint')) . '"'; ?>>
                                            <?php echo $retentionEsc(__('retention.run_rule')); ?>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4" id="<?php echo $retentionEsc($retentionCommentIpAnchor); ?>">
            <div class="card-header">
                <h3 class="card-title"><?php echo $retentionEsc(__('retention.comment_ips.title')); ?></h3>
            </div>
            <div class="card-body">
                <p class="text-secondary"><?php echo $retentionEsc(__('retention.comment_ips.help')); ?></p>
                <form method="post" action="/admin/data-retention#<?php echo $retentionEsc($retentionCommentIpAnchor); ?>" class="row g-3 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo $retentionEsc($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_comment_ips">
                    <div class="col-sm-4 col-lg-3">
                        <label class="form-label" for="retention-comment-ip-days"><?php echo $retentionEsc(__('retention.comment_ips.days')); ?></label>
                        <input type="number" class="form-control" id="retention-comment-ip-days" name="comment_ip_days" min="0" max="<?php echo $retentionMaxDays; ?>" step="1" value="<?php echo (int) ($retentionCommentIps['days'] ?? 0); ?>">
                    </div>
                    <div class="col-sm-5 col-lg-4">
                        <label class="form-label" for="retention-comment-ip-mode"><?php echo $retentionEsc(__('retention.comment_ips.mode')); ?></label>
                        <select class="form-select" id="retention-comment-ip-mode" name="comment_ip_mode">
                            <option value="anonymize"<?php echo ($retentionCommentIps['mode'] ?? '') !== 'remove' ? ' selected' : ''; ?>><?php echo $retentionEsc(__('retention.comment_ips.mode_anonymize')); ?></option>
                            <option value="remove"<?php echo ($retentionCommentIps['mode'] ?? '') === 'remove' ? ' selected' : ''; ?>><?php echo $retentionEsc(__('retention.comment_ips.mode_remove')); ?></option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary"><?php echo $retentionEsc(__('retention.comment_ips.save')); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
