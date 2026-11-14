<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handlePrivacyRequest();

$pageTitle = $t('member.nav.privacy');
$pageKey = 'privacy';
$pageAssets = [];
$memberService = \CMS\Services\MemberService::getInstance();
$privacy = $memberService->getPrivacySettings($controller->getUserId());
$overview = $memberService->getDataOverview($controller->getUserId());
$publicProfileFields = $memberService->getPublicProfileFieldDefinitions();
$privacyToken = $controller->csrfToken('privacy_action');

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-lg-7">
        <form class="card" method="post" action="" aria-labelledby="member-privacy-settings-title">
            <input type="hidden" name="action" value="privacy_save">
            <input type="hidden" name="csrf_token" value="<?= $e($privacyToken) ?>">
            <div class="card-header"><h2 class="card-title" id="member-privacy-settings-title"><?= $e($t('member.privacy.settings')) ?></h2></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="profile_visibility"><?= $e($t('member.privacy.visibility')) ?></label>
                    <select class="form-select" id="profile_visibility" name="profile_visibility">
                        <?php foreach (['public', 'members', 'private'] as $value): ?>
                            <option value="<?= $e($value) ?>"<?= (($privacy['profile_visibility'] ?? 'members') === $value) ? ' selected' : '' ?>><?= $e($t('member.privacy.visibility_' . $value)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-grid gap-2">
                    <label class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_email" value="1"<?= !empty($privacy['show_email']) ? ' checked' : '' ?>>
                        <span class="form-check-label"><?= $e($t('member.privacy.show_email')) ?></span>
                    </label>
                    <label class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_activity" value="1"<?= !empty($privacy['show_activity']) ? ' checked' : '' ?>>
                        <span class="form-check-label"><?= $e($t('member.privacy.show_activity')) ?></span>
                    </label>
                </div>
                <hr>
                <fieldset>
                    <legend class="h4 mb-1"><?= $e($t('member.privacy.public_fields')) ?></legend>
                    <p class="text-secondary small mb-3"><?= $e($t('member.privacy.public_fields_hint')) ?></p>
                    <div class="row g-2">
                        <?php foreach ($publicProfileFields as $fieldKey => $fieldDefinition): ?>
                            <div class="col-12 col-sm-6">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="public_profile_fields[]" value="<?= $e($fieldKey) ?>"<?= in_array($fieldKey, (array)($privacy['public_profile_fields'] ?? []), true) ? ' checked' : '' ?>>
                                    <span class="form-check-label"><?= $e($fieldDefinition['label'] ?? $fieldKey) ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary"><?= $e($t('member.privacy.save')) ?></button>
            </div>
        </form>
    </div>

    <div class="col-12 col-lg-5 vstack gap-4">
        <section class="card" aria-labelledby="member-privacy-data-title">
            <div class="card-header"><h2 class="card-title" id="member-privacy-data-title"><?= $e($t('member.privacy.stored_data')) ?></h2></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-7 text-secondary"><?= $e($t('member.privacy.meta_fields')) ?></dt>
                    <dd class="col-5 text-end"><?= (int)($overview['stored_meta_fields'] ?? 0) ?></dd>
                    <dt class="col-7 text-secondary"><?= $e($t('member.privacy.sessions')) ?></dt>
                    <dd class="col-5 text-end"><?= (int)($overview['sessions'] ?? 0) ?></dd>
                    <dt class="col-7 text-secondary"><?= $e($t('member.nav.notifications')) ?></dt>
                    <dd class="col-5 text-end mb-0"><?= (int)($overview['notifications'] ?? 0) ?></dd>
                </dl>
            </div>
        </section>
        <section class="card" aria-labelledby="member-privacy-actions-title">
            <div class="card-header"><h2 class="card-title" id="member-privacy-actions-title"><?= $e($t('member.privacy.gdpr_actions')) ?></h2></div>
            <div class="card-body d-grid gap-4">
                <form method="post" action="">
                    <input type="hidden" name="action" value="privacy_export">
                    <input type="hidden" name="csrf_token" value="<?= $e($privacyToken) ?>">
                    <button type="submit" class="btn btn-outline-primary w-100"><?= $e($t('member.privacy.export')) ?></button>
                </form>
                <form method="post" action="" data-cms-confirm="<?= $e($t('member.privacy.confirm_delete')) ?>">
                    <input type="hidden" name="action" value="privacy_delete_request">
                    <input type="hidden" name="csrf_token" value="<?= $e($privacyToken) ?>">
                    <div class="mb-2">
                        <label class="form-label" for="privacy_delete_current_password"><?= $e($t('member.security.current_password')) ?></label>
                        <input class="form-control" id="privacy_delete_current_password" name="current_password" type="password" autocomplete="current-password" required aria-describedby="privacy_delete_hint">
                    </div>
                    <button type="submit" class="btn btn-outline-danger w-100"><?= $e($t('member.privacy.delete_request')) ?></button>
                    <p class="form-hint mt-2 mb-0" id="privacy_delete_hint"><?= $e($t('member.privacy.delete_hint')) ?></p>
                </form>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
