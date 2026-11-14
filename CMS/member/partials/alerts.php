<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$controller = $controller ?? \CMS\MemberArea\MemberController::instance();
$t ??= static fn (string $key, array $params = []): string => \CMS\Member\MemberI18n::t($key, $params);
$e ??= static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$memberFlash = $controller->consumeFlash();

if ($memberFlash === null) {
    return;
}

$memberFlashType = \CMS\Member\MemberSanitizer::choice($memberFlash['type'] ?? 'info', ['success', 'info', 'warning', 'danger'], 'info');
$memberFlashMessage = (string) ($memberFlash['message'] ?? '');
$memberFlashPayload = is_array($memberFlash['payload'] ?? null) ? $memberFlash['payload'] : [];
$memberFlashBackupCodes = is_array($memberFlashPayload['backup_codes'] ?? null) ? $memberFlashPayload['backup_codes'] : [];
// Fehler und Warnungen unterbrechen die Sprachausgabe (alert), Erfolg und Info nicht (status) – UI-08.
$memberFlashRole = in_array($memberFlashType, ['danger', 'warning'], true) ? 'alert' : 'status';
?>
<div class="alert alert-<?= $e($memberFlashType) ?> alert-dismissible member-flash" role="<?= $e($memberFlashRole) ?>">
    <div class="d-flex flex-column gap-2">
        <div><?= $e($memberFlashMessage) ?></div>
        <?php if ($memberFlashBackupCodes !== []): ?>
            <div class="small text-secondary" id="member-backup-codes-hint"><?= $e($t('member.security.backup_codes_hint')) ?></div>
            <div class="member-backup-codes" aria-describedby="member-backup-codes-hint">
                <?php foreach ($memberFlashBackupCodes as $memberFlashCode): ?>
                    <code><?= $e($memberFlashCode) ?></code>
                <?php endforeach; ?>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-member-copy-codes data-copied-label="<?= $e($t('member.security.backup_codes_copied')) ?>" data-failed-label="<?= $e($t('member.security.backup_codes_copy_failed')) ?>" hidden>
                    <?= $e($t('member.security.backup_codes_copy')) ?>
                </button>
                <span class="small text-secondary" role="status" aria-live="polite" data-member-copy-status></span>
            </div>
        <?php endif; ?>
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="<?= $e($t('member.a11y.close_message')) ?>"></button>
</div>
