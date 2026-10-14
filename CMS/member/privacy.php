<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handlePrivacyRequest();

$pageTitle = 'Datenschutz';
$pageKey = 'privacy';
$pageAssets = [];
$memberService = \CMS\Services\MemberService::getInstance();
$privacy = $memberService->getPrivacySettings($controller->getUserId());
$overview = $memberService->getDataOverview($controller->getUserId());
$publicProfileFields = $memberService->getPublicProfileFieldDefinitions();

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-lg-7">
        <form class="card" method="post" action="">
            <input type="hidden" name="action" value="privacy_save">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('privacy_action'), ENT_QUOTES) ?>">
            <div class="card-header"><h3 class="card-title">Sichtbarkeit & Datenschutz</h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="profile_visibility">Profil sichtbar für</label>
                    <select class="form-select" id="profile_visibility" name="profile_visibility">
                        <?php foreach (['public' => 'Öffentlich', 'members' => 'Nur Mitglieder', 'private' => 'Nur ich'] as $value => $label): ?>
                            <option value="<?= htmlspecialchars($value) ?>" <?= (($privacy['profile_visibility'] ?? 'members') === $value) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-grid gap-2">
                    <label class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_email" value="1" <?= !empty($privacy['show_email']) ? 'checked' : '' ?>>
                        <span class="form-check-label">E-Mail im Profil anzeigen</span>
                    </label>
                    <label class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_activity" value="1" <?= !empty($privacy['show_activity']) ? 'checked' : '' ?>>
                        <span class="form-check-label">Aktivitäten für andere Mitglieder sichtbar machen</span>
                    </label>
                </div>
                <hr>
                <div class="mb-2">
                    <h4 class="h5 mb-2">Öffentliche Profilfelder</h4>
                    <p class="text-secondary small mb-3">Diese Angaben dürfen auf deiner öffentlichen Autorenseite angezeigt werden.</p>
                </div>
                <div class="row g-2">
                    <?php foreach ($publicProfileFields as $fieldKey => $fieldDefinition): ?>
                    <div class="col-sm-6">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="public_profile_fields[]" value="<?= htmlspecialchars((string)$fieldKey, ENT_QUOTES) ?>" <?= in_array($fieldKey, (array)($privacy['public_profile_fields'] ?? []), true) ? 'checked' : '' ?>>
                            <span class="form-check-label"><?= htmlspecialchars((string)($fieldDefinition['label'] ?? $fieldKey)) ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary">Datenschutzeinstellungen speichern</button>
            </div>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Gespeicherte Daten</h3></div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-7 text-secondary">Meta-Felder</dt>
                    <dd class="col-5 text-end"><?= (int)($overview['stored_meta_fields'] ?? 0) ?></dd>
                    <dt class="col-7 text-secondary">Aktive Sessions</dt>
                    <dd class="col-5 text-end"><?= (int)($overview['sessions'] ?? 0) ?></dd>
                    <dt class="col-7 text-secondary">Benachrichtigungen</dt>
                    <dd class="col-5 text-end"><?= (int)($overview['notifications'] ?? 0) ?></dd>
                </dl>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3 class="card-title">DSGVO-Aktionen</h3></div>
            <div class="card-body d-grid gap-3">
                <form method="post" action="">
                    <input type="hidden" name="action" value="privacy_export">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('privacy_action'), ENT_QUOTES) ?>">
                    <button type="submit" class="btn btn-outline-primary w-100">Meine Daten exportieren</button>
                </form>
                <form method="post" action="" data-cms-confirm="Löschung deines Kontos wirklich anfordern?">
                    <input type="hidden" name="action" value="privacy_delete_request">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('privacy_action'), ENT_QUOTES) ?>">
                    <button type="submit" class="btn btn-outline-danger w-100">Account-Löschung anfordern</button>
                </form>
                <div class="text-secondary small">Die Löschanfrage markiert dein Konto zur Entfernung nach Ablauf der vorgesehenen Frist.</div>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
