<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/includes/bootstrap.php';

$controller->handleSecurityRequest();

$pageTitle = $t('member.nav.security');
$pageKey = 'security';
$pageAssets = [];
$securityData = $controller->getSecurityPageData();
$security = is_array($securityData['security'] ?? null) ? $securityData['security'] : [];
$sessions = is_array($securityData['sessions'] ?? null) ? $securityData['sessions'] : [];
$credentials = is_array($securityData['credentials'] ?? null) ? $securityData['credentials'] : [];
$passkeyPayload = is_array($securityData['passkey_payload'] ?? null) ? $securityData['passkey_payload'] : ['available' => false, 'options_json' => '{}'];
$totpSetup = is_array($securityData['totp_setup'] ?? null) ? $securityData['totp_setup'] : null;
$totpQrUrl = trim((string)($totpSetup['qr_data_uri'] ?? $totpSetup['qr_url'] ?? ''));
$totpSecret = trim((string)($totpSetup['secret'] ?? ''));
$totpOtpUri = trim((string)($totpSetup['otp_uri'] ?? ''));
$score = max(0, min(100, (int)($security['score'] ?? 0)));
$recommendations = array_values(array_filter((array)($security['recommendations'] ?? []), 'is_array'));
$mfaToken = $controller->csrfToken('security_mfa');
$passkeyToken = $controller->csrfToken('security_passkey');
$memberProgressValues = [$score];

/** Passwortfeld für die Re-Authentifizierung sicherheitsrelevanter Aktionen. */
$passwordField = static function (string $id) use ($e, $t): void {
    ?>
    <div>
        <label class="form-label" for="<?= $e($id) ?>"><?= $e($t('member.security.current_password')) ?></label>
        <input class="form-control" id="<?= $e($id) ?>" name="current_password" type="password" autocomplete="current-password" required>
    </div>
    <?php
};

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-xl-4 vstack gap-4">
        <section class="card" aria-labelledby="member-security-score-title">
            <div class="card-header">
                <h2 class="card-title" id="member-security-score-title"><?= $e($t('member.kpi.security_score')) ?></h2>
            </div>
            <div class="card-body text-center">
                <p class="display-4 fw-bold text-primary mb-2"><?= $score ?><span class="visually-hidden"> / 100</span></p>
                <p class="text-secondary mb-3"><?= $e($security['score_message'] ?? '') ?></p>
                <div class="progress progress-sm">
                    <div class="progress-bar bg-primary member-progress" data-member-progress="<?= $score ?>" role="progressbar" aria-valuenow="<?= $score ?>" aria-valuemin="0" aria-valuemax="100" aria-labelledby="member-security-score-title"></div>
                </div>
            </div>
        </section>
        <section class="card" aria-labelledby="member-security-recommendations-title">
            <div class="card-header"><h2 class="card-title" id="member-security-recommendations-title"><?= $e($t('member.security.recommendations')) ?></h2></div>
            <?php if ($recommendations === []): ?>
                <div class="card-body">
                    <div class="member-empty">
                        <span class="member-empty__icon" aria-hidden="true">✓</span>
                        <p class="member-empty__text mb-0"><?= $e($t('member.security.recommendations_empty')) ?></p>
                    </div>
                </div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($recommendations as $recommendation): ?>
                        <?php $done = !empty($recommendation['done']); ?>
                        <li class="list-group-item d-flex gap-3">
                            <span class="badge <?= $done ? 'bg-green-lt text-green' : 'bg-amber-lt text-amber' ?> mt-1">
                                <span aria-hidden="true"><?= $done ? '✓' : '!' ?></span>
                                <span class="visually-hidden"><?= $e($t($done ? 'member.security.done' : 'member.security.open')) ?></span>
                            </span>
                            <div><?= $e($recommendation['text'] ?? '') ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <div class="col-12 col-xl-8 vstack gap-4">
        <form class="card" method="post" action="" aria-labelledby="member-password-title">
            <input type="hidden" name="action" value="password_change">
            <input type="hidden" name="csrf_token" value="<?= $e($controller->csrfToken('security_password')) ?>">
            <div class="card-header"><h2 class="card-title" id="member-password-title"><?= $e($t('member.security.password_change')) ?></h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="current_password"><?= $e($t('member.security.current_password')) ?></label>
                        <input class="form-control" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="new_password"><?= $e($t('member.security.new_password')) ?></label>
                        <input class="form-control" id="new_password" name="new_password" type="password" autocomplete="new-password" required>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="confirm_password"><?= $e($t('member.security.confirm_password')) ?></label>
                        <input class="form-control" id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" required>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary"><?= $e($t('member.security.password_save')) ?></button>
            </div>
        </form>

        <section class="card" aria-labelledby="member-mfa-title">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title" id="member-mfa-title"><?= $e($t('member.security.mfa')) ?></h2>
                <span class="badge <?= !empty($securityData['totp_enabled']) ? 'bg-green-lt text-green' : 'bg-secondary-lt text-secondary' ?>">
                    <?= $e($t(!empty($securityData['totp_enabled']) ? 'member.common.active' : 'member.common.inactive')) ?>
                </span>
            </div>
            <div class="card-body">
                <?php if ($totpSetup !== null): ?>
                    <div class="row g-4 align-items-center">
                        <div class="col-12 col-md-4 text-center">
                            <?php if ($totpQrUrl !== ''): ?>
                                <img class="img-fluid rounded border" src="<?= $e($totpQrUrl) ?>" alt="<?= $e($t('member.security.totp_qr_alt')) ?>" width="200" height="200">
                            <?php else: ?>
                                <div class="border rounded p-4 bg-light text-secondary"><?= $e($t('member.security.totp_qr_missing')) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-md-8">
                            <p class="text-secondary"><?= $e($t('member.security.totp_instructions')) ?></p>
                            <?php if ($totpSecret !== ''): ?>
                                <div class="mb-3">
                                    <div class="small text-secondary mb-1"><?= $e($t('member.security.totp_manual')) ?></div>
                                    <code class="text-break"><?= $e($totpSecret) ?></code>
                                </div>
                            <?php endif; ?>
                            <?php if ($totpOtpUri !== ''): ?>
                                <div class="mb-3 small text-secondary text-break">
                                    OTP-URI: <a href="<?= $e($totpOtpUri) ?>"><?= $e($totpOtpUri) ?></a>
                                </div>
                            <?php endif; ?>
                            <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end">
                                <input type="hidden" name="action" value="totp_confirm">
                                <input type="hidden" name="csrf_token" value="<?= $e($mfaToken) ?>">
                                <div>
                                    <label class="form-label" for="totp_code"><?= $e($t('member.security.totp_code')) ?></label>
                                    <input class="form-control" id="totp_code" name="totp_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>
                                </div>
                                <button type="submit" class="btn btn-primary"><?= $e($t('member.security.totp_activate')) ?></button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-secondary"><?= $e($t('member.security.mfa_intro')) ?></p>
                    <div class="d-flex flex-wrap gap-3">
                        <?php if (empty($securityData['totp_enabled'])): ?>
                            <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end">
                                <input type="hidden" name="action" value="totp_start">
                                <input type="hidden" name="csrf_token" value="<?= $e($mfaToken) ?>">
                                <?php $passwordField('totp_start_current_password'); ?>
                                <button type="submit" class="btn btn-primary"><?= $e($t('member.security.totp_setup')) ?></button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end" data-cms-confirm="<?= $e($t('member.security.confirm_backup')) ?>">
                                <input type="hidden" name="action" value="backup_generate">
                                <input type="hidden" name="csrf_token" value="<?= $e($mfaToken) ?>">
                                <?php $passwordField('backup_current_password'); ?>
                                <button type="submit" class="btn btn-outline-primary"><?= $e($t('member.security.backup_renew')) ?></button>
                            </form>
                            <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end" data-cms-confirm="<?= $e($t('member.security.confirm_mfa_disable')) ?>">
                                <input type="hidden" name="action" value="totp_disable">
                                <input type="hidden" name="csrf_token" value="<?= $e($mfaToken) ?>">
                                <?php $passwordField('mfa_disable_current_password'); ?>
                                <button type="submit" class="btn btn-outline-danger"><?= $e($t('member.security.mfa_disable')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="card-footer text-secondary small">
                <?= $e($t('member.security.backup_count', ['count' => (int)($securityData['backup_count'] ?? 0)])) ?>
            </div>
        </section>

        <section class="card" aria-labelledby="member-passkeys-title">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title" id="member-passkeys-title"><?= $e($t('member.security.passkeys')) ?></h2>
                <span class="badge <?= !empty($passkeyPayload['available']) ? 'bg-blue-lt text-blue' : 'bg-secondary-lt text-secondary' ?>">
                    <?= $e($t(!empty($passkeyPayload['available']) ? 'member.security.available' : 'member.security.unavailable')) ?>
                </span>
            </div>
            <div class="card-body">
                <p class="text-secondary"><?= $e($t('member.security.passkeys_intro')) ?></p>
                <?php if (!empty($passkeyPayload['available'])): ?>
                    <form method="post" action="" data-passkey-form data-passkey-options="<?= $e((string)($passkeyPayload['options_json'] ?? '{}')) ?>"
                          data-msg-options="<?= $e($t('member.security.passkey_error_options')) ?>"
                          data-msg-create="<?= $e($t('member.security.passkey_error_create')) ?>"
                          data-msg-cancelled="<?= $e($t('member.security.passkey_error_cancelled')) ?>"
                          data-msg-unsupported="<?= $e($t('member.security.passkey_error_unsupported')) ?>">
                        <input type="hidden" name="action" value="passkey_register">
                        <input type="hidden" name="csrf_token" value="<?= $e($passkeyToken) ?>">
                        <input type="hidden" name="client_data_json" value="">
                        <input type="hidden" name="attestation_object" value="">
                        <div class="row g-3 align-items-end">
                            <div class="col-12 col-md-4">
                                <label class="form-label" for="credential_name"><?= $e($t('member.security.passkey_name')) ?></label>
                                <input class="form-control" id="credential_name" name="credential_name" type="text" maxlength="100" value="<?= $e($t('member.security.passkey_default_name')) ?>">
                            </div>
                            <div class="col-12 col-md-4">
                                <?php $passwordField('passkey_current_password'); ?>
                            </div>
                            <div class="col-12 col-md-4">
                                <button type="button" class="btn btn-primary" data-passkey-register><?= $e($t('member.security.passkey_register')) ?></button>
                            </div>
                        </div>
                        <div class="alert alert-danger mt-3 mb-0" role="alert" data-passkey-error hidden></div>
                    </form>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <caption class="visually-hidden"><?= $e($t('member.security.passkeys_caption')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= $e($t('member.security.col_name')) ?></th>
                            <th scope="col"><?= $e($t('member.security.col_created')) ?></th>
                            <th scope="col" class="w-1"><span class="visually-hidden"><?= $e($t('member.media.actions')) ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($credentials === []): ?>
                            <tr><td colspan="3" class="text-secondary"><?= $e($t('member.security.passkeys_empty')) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($credentials as $credential): ?>
                                <?php
                                $credentialName = is_array($credential) ? (string)($credential['name'] ?? 'Passkey') : (string)($credential->name ?? 'Passkey');
                                $credentialCreatedAt = is_array($credential) ? (string)($credential['created_at'] ?? '') : (string)($credential->created_at ?? '');
                                $credentialRecordId = is_array($credential) ? (int)($credential['id'] ?? 0) : (int)($credential->id ?? 0);
                                $passwordId = 'passkey_delete_password_' . $credentialRecordId;
                                ?>
                                <tr>
                                    <td><?= $e($credentialName) ?></td>
                                    <td><?= $e(MemberI18n::formatDate($credentialCreatedAt)) ?></td>
                                    <td>
                                        <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end" data-cms-confirm="<?= $e($t('member.security.confirm_passkey_delete')) ?>">
                                            <input type="hidden" name="action" value="passkey_delete">
                                            <input type="hidden" name="credential_id" value="<?= $credentialRecordId ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $e($passkeyToken) ?>">
                                            <label class="visually-hidden" for="<?= $e($passwordId) ?>"><?= $e($t('member.security.current_password')) ?></label>
                                            <input class="form-control form-control-sm member-inline-password" id="<?= $e($passwordId) ?>" name="current_password" type="password" autocomplete="current-password" placeholder="<?= $e($t('member.security.current_password')) ?>" required>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"><?= $e($t('member.security.passkey_remove')) ?><span class="visually-hidden">: <?= $e($credentialName) ?></span></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card" aria-labelledby="member-sessions-title">
            <div class="card-header"><h2 class="card-title" id="member-sessions-title"><?= $e($t('member.security.sessions')) ?></h2></div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <caption class="visually-hidden"><?= $e($t('member.security.sessions_caption')) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= $e($t('member.security.col_session')) ?></th>
                            <th scope="col"><?= $e($t('member.security.col_client')) ?></th>
                            <th scope="col"><?= $e($t('member.security.col_last_active')) ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($sessions === []): ?>
                            <tr><td colspan="3" class="text-secondary"><?= $e($t('member.security.sessions_empty')) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($sessions as $session): ?>
                                <?php $sessionKey = (string) ($session->id ?? ''); ?>
                                <tr>
                                    <td>
                                        <code><?= $e(substr($sessionKey, 0, 8)) ?></code>
                                        <?php if ($sessionKey !== '' && session_id() !== '' && hash_equals($sessionKey, hash('sha256', session_id()))): ?>
                                            <span class="badge bg-green-lt ms-1"><?= $e($t('member.security.this_session')) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= $e(($session->ip_address ?? '') !== '' ? $session->ip_address : $t('member.common.unknown')) ?></div>
                                        <div class="text-secondary small text-break"><?= $e($session->user_agent ?? '') ?></div>
                                    </td>
                                    <td><?= $e(MemberI18n::formatDate($session->last_activity ?? '')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                <form method="post" action="" class="d-flex flex-wrap gap-2 align-items-end" data-cms-confirm="<?= $e($t('member.security.confirm_revoke')) ?>">
                    <input type="hidden" name="action" value="sessions_revoke_all">
                    <input type="hidden" name="csrf_token" value="<?= $e($controller->csrfToken('security_sessions')) ?>">
                    <?php $passwordField('sessions_revoke_current_password'); ?>
                    <button type="submit" class="btn btn-outline-danger"><?= $e($t('member.security.revoke_all')) ?></button>
                </form>
                <p class="text-secondary small mt-2 mb-0"><?= $e($t('member.security.revoke_hint')) ?></p>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/partials/footer.php';
