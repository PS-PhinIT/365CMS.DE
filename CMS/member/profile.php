<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handleProfileRequest();

$pageTitle = 'Profil';
$pageKey = 'profile';
$pageAssets = [];
$user = $controller->getCurrentUser();
$meta = \CMS\Services\MemberService::getInstance()->getUserMeta($controller->getUserId());
$profileFields = (array)($settings['profile_fields'] ?? []);
$profileFieldDefinitions = is_array($settings['profile_field_definitions'] ?? null) ? $settings['profile_field_definitions'] : $controller->getProfileFieldDefinitions();
$requiredProfileFields = array_map('strval', (array)($settings['required_profile_fields'] ?? ['username', 'email']));
$profileCompletion = $controller->getProfileCompletion();
$profileValue = static function (string $field) use ($user, $meta): string {
    return match ($field) {
        'username' => (string)($user->username ?? ''),
        'email' => (string)($user->email ?? ''),
        default => (string)($meta[$field] ?? ''),
    };
};

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-lg-8">
        <form class="card" method="post" action="">
            <input type="hidden" name="action" value="profile_save">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('profile_save'), ENT_QUOTES) ?>">
            <div class="card-header">
                <h3 class="card-title">Persönliche Angaben</h3>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="display_name">Anzeigename</label>
                        <input class="form-control" id="display_name" name="display_name" type="text" value="<?= htmlspecialchars((string)($user->display_name ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <?php foreach ($profileFields as $field): ?>
                        <?php
                        $field = (string)$field;
                        $definition = is_array($profileFieldDefinitions[$field] ?? null) ? $profileFieldDefinitions[$field] : ['label' => $field, 'type' => 'text'];
                        $label = (string)($definition['label'] ?? $field);
                        $type = (string)($definition['type'] ?? 'text');
                        $isRequired = in_array($field, $requiredProfileFields, true) || !empty($definition['required']);
                        $value = $profileValue($field);
                        $inputType = in_array($type, ['email', 'url', 'date'], true) ? $type : 'text';
                        $columnClass = in_array($type, ['textarea', 'wysiwyg'], true) ? 'col-12' : 'col-md-6';
                        ?>
                        <div class="<?= htmlspecialchars($columnClass, ENT_QUOTES, 'UTF-8') ?>">
                            <label class="form-label" for="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?><?php if ($isRequired): ?> <span class="text-danger" aria-label="Pflichtfeld">*</span><?php endif; ?>
                            </label>
                            <?php if ($type === 'wysiwyg'): ?>
                                <?= \CMS\Services\EditorService::getInstance()->render($field, $value, [
                                    'height' => 320,
                                    'context' => 'member_' . $field,
                                    'content_width' => 760,
                                    'aria_label' => $label . ' Editor',
                                ]) ?>
                            <?php elseif ($type === 'textarea'): ?>
                                <textarea class="form-control" id="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" rows="6" <?= $isRequired ? 'required' : '' ?>><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                            <?php else: ?>
                                <input class="form-control" id="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" type="<?= htmlspecialchars($inputType, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $isRequired ? 'required' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-md-6">
                        <label class="form-label" for="profile_current_password">Aktuelles Passwort</label>
                        <input class="form-control" id="profile_current_password" name="current_password" type="password" autocomplete="current-password" aria-describedby="profile_current_password_hint">
                        <div class="form-hint" id="profile_current_password_hint">Nur erforderlich, wenn du deine E-Mail-Adresse änderst.</div>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary">Profil speichern</button>
            </div>
        </form>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-body text-center">
                <?php if ($controller->getAvatarUrl() !== ''): ?>
                    <span class="avatar avatar-xl mb-3" style="background-image: url('<?= htmlspecialchars($controller->getAvatarUrl(), ENT_QUOTES) ?>')"></span>
                <?php else: ?>
                    <span class="avatar avatar-xl mb-3"><?= htmlspecialchars($controller->getInitials()) ?></span>
                <?php endif; ?>
                <h3 class="m-0 mb-1"><?= htmlspecialchars($controller->getDisplayName()) ?></h3>
                <div class="text-secondary"><?= htmlspecialchars((string)($user->email ?? '')) ?></div>
            </div>
        </div>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title">Profilstatus</h3>
                <span class="badge bg-primary-lt"><?= (int)($profileCompletion['percentage'] ?? 0) ?>%</span>
            </div>
            <div class="card-body">
                <div class="progress mb-3">
                    <div class="progress-bar bg-primary" style="width: <?= (int)($profileCompletion['percentage'] ?? 0) ?>%"></div>
                </div>
                <ul class="list-unstyled mb-0">
                    <?php foreach ($profileFields as $field): ?>
                        <?php
                        $field = (string)$field;
                        $definition = is_array($profileFieldDefinitions[$field] ?? null) ? $profileFieldDefinitions[$field] : ['label' => $field];
                        $hasValue = trim($profileValue($field)) !== '';
                        ?>
                        <li class="d-flex justify-content-between py-1">
                            <span><?= htmlspecialchars((string)($definition['label'] ?? ucwords(str_replace('_', ' ', $field))), ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="badge <?= $hasValue ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary' ?>">
                                <?= $hasValue ? 'Erledigt' : 'Offen' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
