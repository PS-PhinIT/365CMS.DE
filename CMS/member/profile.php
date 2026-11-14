<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handleProfileRequest();

$pageTitle = $t('member.nav.profile');
$pageKey = 'profile';
$pageAssets = [];
$user = $controller->getCurrentUser();
$meta = \CMS\Services\MemberService::getInstance()->getUserMeta($controller->getUserId());
$profileFields = (array)($settings['profile_fields'] ?? []);
$profileFieldDefinitions = is_array($settings['profile_field_definitions'] ?? null) ? $settings['profile_field_definitions'] : $controller->getProfileFieldDefinitions();
$requiredProfileFields = array_map('strval', (array)($settings['required_profile_fields'] ?? ['username', 'email']));
$profileCompletion = $controller->getProfileCompletion();
$profilePercentage = max(0, min(100, (int)($profileCompletion['percentage'] ?? 0)));
$pendingEmailChange = \CMS\Services\EmailChangeService::getInstance()->getPending($controller->getUserId());
$avatarUrl = $controller->getAvatarUrl();
$maxLengths = ['first_name' => 100, 'last_name' => 100, 'email' => 100, 'phone' => 40, 'company' => 120, 'position' => 120, 'location' => 120, 'username' => 50];
$profileValue = static function (string $field) use ($user, $meta): string {
    return match ($field) {
        'username' => (string)($user->username ?? ''),
        'email' => (string)($user->email ?? ''),
        default => (string)($meta[$field] ?? ''),
    };
};
$memberProgressValues = [$profilePercentage];

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-lg-8">
        <form class="card" method="post" action="" aria-labelledby="member-profile-form-title">
            <input type="hidden" name="action" value="profile_save">
            <input type="hidden" name="csrf_token" value="<?= $e($controller->csrfToken('profile_save')) ?>">
            <div class="card-header">
                <h2 class="card-title" id="member-profile-form-title"><?= $e($t('member.profile.personal_data')) ?></h2>
            </div>
            <div class="card-body">
                <?php if ($pendingEmailChange !== null): ?>
                    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2" role="status">
                        <div>
                            <?= $e($t('member.profile.email_pending', [
                                'email' => (string)$pendingEmailChange['email'],
                                'date' => MemberI18n::formatDate((string)(int)$pendingEmailChange['expires_at']),
                                'current' => (string)($user->email ?? ''),
                            ])) ?>
                        </div>
                        <button type="submit" form="memberEmailChangeCancelForm" class="btn btn-sm btn-outline-secondary" data-cms-confirm="<?= $e($t('member.profile.email_cancel_confirm')) ?>"><?= $e($t('member.profile.email_cancel')) ?></button>
                    </div>
                <?php endif; ?>
                <fieldset>
                    <legend class="visually-hidden"><?= $e($t('member.profile.personal_data')) ?></legend>
                    <p class="text-secondary small" id="member-required-legend"><?= $e($t('member.profile.required_legend')) ?></p>
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="display_name"><?= $e($t('member.profile.display_name')) ?></label>
                            <input class="form-control" id="display_name" name="display_name" type="text" maxlength="100" autocomplete="nickname" value="<?= $e($user->display_name ?? '') ?>">
                        </div>
                        <?php foreach ($profileFields as $field): ?>
                            <?php
                            $field = (string)$field;
                            $definition = is_array($profileFieldDefinitions[$field] ?? null) ? $profileFieldDefinitions[$field] : ['label' => $field, 'type' => 'text'];
                            $label = (string)($definition['label'] ?? $field);
                            $type = (string)($definition['type'] ?? 'text');
                            $isRequired = in_array($field, $requiredProfileFields, true) || !empty($definition['required']);
                            $value = $profileValue($field);
                            $inputType = in_array($type, ['email', 'url', 'date', 'tel'], true) ? $type : ($field === 'phone' ? 'tel' : 'text');
                            $columnClass = in_array($type, ['textarea', 'wysiwyg'], true) ? 'col-12' : 'col-12 col-md-6';
                            $autocomplete = match ($field) {
                                'first_name' => 'given-name',
                                'last_name' => 'family-name',
                                'email' => 'email',
                                'username' => 'username',
                                'birth_date' => 'bday',
                                'phone' => 'tel',
                                'company' => 'organization',
                                'position' => 'organization-title',
                                'website', 'social' => 'url',
                                default => '',
                            };
                            $attributes = ($isRequired ? ' required aria-required="true"' : '')
                                . (isset($maxLengths[$field]) ? ' maxlength="' . (int)$maxLengths[$field] . '"' : '')
                                . ($autocomplete !== '' ? ' autocomplete="' . $e($autocomplete) . '"' : '')
                                . ($field === 'birth_date' ? ' max="' . $e(date('Y-m-d')) . '"' : '');
                            ?>
                            <div class="<?= $e($columnClass) ?>">
                                <label class="form-label<?= $isRequired ? ' required' : '' ?>" for="<?= $e($field) ?>">
                                    <?= $e($label) ?>
                                </label>
                                <?php if ($type === 'wysiwyg'): ?>
                                    <?= \CMS\Services\EditorService::getInstance()->render($field, $value, [
                                        'height' => 320,
                                        'context' => 'member_' . $field,
                                        'content_width' => 760,
                                        'aria_label' => $label,
                                    ]) ?>
                                <?php elseif ($type === 'textarea'): ?>
                                    <textarea class="form-control" id="<?= $e($field) ?>" name="<?= $e($field) ?>" rows="6"<?= $attributes ?>><?= $e($value) ?></textarea>
                                <?php else: ?>
                                    <input class="form-control" id="<?= $e($field) ?>" name="<?= $e($field) ?>" type="<?= $e($inputType) ?>" value="<?= $e($value) ?>"<?= $attributes ?>>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="profile_current_password"><?= $e($t('member.security.current_password')) ?></label>
                            <input class="form-control" id="profile_current_password" name="current_password" type="password" autocomplete="current-password" aria-describedby="profile_current_password_hint">
                            <div class="form-hint" id="profile_current_password_hint"><?= $e($t('member.profile.current_password_hint')) ?></div>
                        </div>
                    </div>
                </fieldset>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary"><?= $e($t('member.profile.save')) ?></button>
            </div>
        </form>
        <?php if ($pendingEmailChange !== null): ?>
            <form id="memberEmailChangeCancelForm" method="post" action="" class="d-none">
                <input type="hidden" name="action" value="email_change_cancel">
                <input type="hidden" name="csrf_token" value="<?= $e($controller->csrfToken('email_change_cancel')) ?>">
            </form>
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-4 vstack gap-4">
        <section class="card" aria-label="<?= $e($t('member.profile.summary')) ?>">
            <div class="card-body text-center">
                <?php if ($avatarUrl !== ''): ?>
                    <img class="avatar avatar-xl mb-3 member-avatar-img" src="<?= $e($avatarUrl) ?>" alt="<?= $e($t('member.profile.avatar_alt', ['name' => $controller->getDisplayName()])) ?>" width="96" height="96">
                <?php else: ?>
                    <span class="avatar avatar-xl mb-3" aria-hidden="true"><?= $e($controller->getInitials()) ?></span>
                <?php endif; ?>
                <p class="h3 m-0 mb-1"><?= $e($controller->getDisplayName()) ?></p>
                <div class="text-secondary text-break"><?= $e($user->email ?? '') ?></div>
            </div>
        </section>
        <section class="card" aria-labelledby="member-profile-status-title">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title" id="member-profile-status-title"><?= $e($t('member.profile.status')) ?></h2>
                <span class="badge bg-primary-lt"><?= $profilePercentage ?>%</span>
            </div>
            <div class="card-body">
                <div class="progress mb-3">
                    <div class="progress-bar bg-primary member-progress" data-member-progress="<?= $profilePercentage ?>" role="progressbar" aria-valuenow="<?= $profilePercentage ?>" aria-valuemin="0" aria-valuemax="100" aria-labelledby="member-profile-status-title"></div>
                </div>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($profileFields as $field): ?>
                        <?php
                        $field = (string)$field;
                        $definition = is_array($profileFieldDefinitions[$field] ?? null) ? $profileFieldDefinitions[$field] : ['label' => $field];
                        $hasValue = trim($profileValue($field)) !== '';
                        ?>
                        <li class="d-flex justify-content-between py-1">
                            <span><?= $e($definition['label'] ?? ucwords(str_replace('_', ' ', $field))) ?></span>
                            <span class="badge <?= $hasValue ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary' ?>">
                                <?= $e($t($hasValue ? 'member.profile.done' : 'member.profile.open')) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
