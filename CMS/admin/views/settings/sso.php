<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Einstellungen › SSO (Microsoft Entra ID).
 *
 * @var array<string,mixed> $ssoSettings
 * @var array<string,string> $ssoRoles
 * @var array<string,string>|null $ssoAlert
 * @var string $ssoRedirectUri
 * @var int|null $ssoSecretDaysLeft
 * @var string $ssoMailTenant
 * @var string $ssoCsrfToken
 * @var string $ssoTestCsrfToken
 */

use CMS\Auth\SSO\EntraSettings;

$ssoEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$ssoT = static fn (string $key, string|int ...$args): string => htmlspecialchars($args !== [] ? vsprintf((string) __($key), $args) : (string) __($key), ENT_QUOTES, 'UTF-8');
$ssoAlert = is_array($ssoAlert ?? null) ? $ssoAlert : null;
$ssoChecked = static fn (string $key): string => !empty($ssoSettings[$key]) ? ' checked' : '';
$ssoRules = (array) ($ssoSettings['role_mapping'] ?? []);
$ssoRoleOptions = static function (string $selected) use ($ssoRoles, $ssoEsc): string {
    $html = '';
    foreach ($ssoRoles as $roleKey => $roleLabel) {
        $html .= '<option value="' . $ssoEsc($roleKey) . '"' . ((string) $roleKey === $selected ? ' selected' : '') . '>' . $ssoEsc($roleLabel) . '</option>';
    }

    return $html;
};
$ssoTypeOptions = static function (string $selected) use ($ssoT): string {
    $html = '';
    foreach (['role' => 'sso_settings.mapping.type_role', 'group' => 'sso_settings.mapping.type_group'] as $type => $labelKey) {
        $html .= '<option value="' . $type . '"' . ($type === $selected ? ' selected' : '') . '>' . $ssoT($labelKey) . '</option>';
    }

    return $html;
};
$ssoRow = static function (array $rule) use ($ssoEsc, $ssoT, $ssoRoleOptions, $ssoTypeOptions): string {
    return '<tr data-sso-mapping-row>'
        . '<td><select class="form-select form-select-sm" name="mapping_type[]" aria-label="' . $ssoT('sso_settings.mapping.type') . '">' . $ssoTypeOptions((string) ($rule['type'] ?? 'role')) . '</select></td>'
        . '<td><input class="form-control form-control-sm" type="text" name="mapping_value[]" maxlength="120" value="' . $ssoEsc($rule['value'] ?? '') . '" aria-label="' . $ssoT('sso_settings.mapping.value') . '"></td>'
        . '<td><select class="form-select form-select-sm" name="mapping_role[]" aria-label="' . $ssoT('sso_settings.mapping.role') . '">' . $ssoRoleOptions((string) ($rule['role'] ?? 'member')) . '</select></td>'
        . '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-sso-mapping-remove>' . $ssoT('sso_settings.mapping.remove') . '</button></td>'
        . '</tr>';
};
?>
<div class="page-wrapper">
    <div class="page-header d-print-none">
        <div class="container-xl">
            <h1 class="page-title"><?= $ssoT('sso_settings.title') ?></h1>
        </div>
    </div>
    <div class="page-body">
        <div class="container-xl">
            <?php require __DIR__ . '/partials-tabs.php'; ?>
            <?php if ($ssoAlert !== null): ?>
                <div class="alert alert-<?= $ssoEsc($ssoAlert['type'] ?? 'info') ?> mt-3" role="status"><?= $ssoEsc($ssoAlert['message'] ?? '') ?></div>
            <?php endif; ?>
            <?php if ($ssoSecretDaysLeft !== null && $ssoSecretDaysLeft <= 30): ?>
                <div class="alert alert-<?= $ssoSecretDaysLeft < 0 ? 'danger' : 'warning' ?> mt-3" role="status">
                    <?= $ssoSecretDaysLeft < 0 ? $ssoT('sso_settings.secret_expired') : $ssoT('sso_settings.secret_expires_soon', max(0, $ssoSecretDaysLeft)) ?>
                </div>
            <?php endif; ?>

            <div class="card mt-3">
                <div class="card-body">
                    <p class="text-secondary mb-2"><?= $ssoT('sso_settings.intro') ?></p>
                    <label class="form-label" for="sso-redirect-uri"><?= $ssoT('sso_settings.redirect_uri') ?></label>
                    <input class="form-control font-monospace" id="sso-redirect-uri" type="text" readonly value="<?= $ssoEsc($ssoRedirectUri) ?>">
                    <p class="form-hint mb-0"><?= $ssoT('sso_settings.redirect_uri_hint') ?></p>
                </div>
            </div>

            <form method="post" action="/admin/settings?tab=sso" class="card mt-3" id="sso-settings" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $ssoEsc($ssoCsrfToken) ?>">
                <input type="hidden" name="form" value="sso_settings">
                <div class="card-header">
                    <h2 class="card-title"><?= $ssoT('sso_settings.app.heading') ?></h2>
                </div>
                <div class="card-body">
                    <label class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="enabled" value="1"<?= $ssoChecked('enabled') ?>>
                        <span class="form-check-label"><?= $ssoT('sso_settings.enabled') ?></span>
                    </label>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="sso-tenant"><?= $ssoT('sso_settings.tenant_id') ?></label>
                            <input class="form-control font-monospace" id="sso-tenant" name="tenant_id" type="text" maxlength="36" placeholder="00000000-0000-0000-0000-000000000000" value="<?= $ssoEsc($ssoSettings['tenant_id']) ?>">
                            <?php if ($ssoMailTenant !== '' && $ssoMailTenant !== $ssoSettings['tenant_id']): ?>
                                <p class="form-hint"><?= $ssoT('sso_settings.tenant_from_mail') ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline" data-sso-fill-target="sso-tenant" data-sso-fill-value="<?= $ssoEsc($ssoMailTenant) ?>"><?= $ssoEsc($ssoMailTenant) ?></button>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sso-client"><?= $ssoT('sso_settings.client_id') ?></label>
                            <input class="form-control font-monospace" id="sso-client" name="client_id" type="text" maxlength="36" placeholder="00000000-0000-0000-0000-000000000000" value="<?= $ssoEsc($ssoSettings['client_id']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sso-secret"><?= $ssoT('sso_settings.client_secret') ?></label>
                            <input class="form-control" id="sso-secret" name="client_secret" type="password" maxlength="512" autocomplete="new-password"
                                   placeholder="<?= $ssoSettings['client_secret_set'] ? $ssoT('sso_settings.client_secret_stored') : '' ?>">
                            <p class="form-hint"><?= $ssoT('sso_settings.client_secret_hint') ?></p>
                            <?php if ($ssoSettings['client_secret_set']): ?>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="clear_secret" value="1">
                                    <span class="form-check-label"><?= $ssoT('sso_settings.client_secret_clear') ?></span>
                                </label>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sso-secret-expires"><?= $ssoT('sso_settings.client_secret_expires') ?></label>
                            <input class="form-control" id="sso-secret-expires" name="client_secret_expires" type="date" value="<?= $ssoEsc($ssoSettings['client_secret_expires']) ?>">
                            <p class="form-hint"><?= $ssoT('sso_settings.client_secret_expires_hint') ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sso-tenants"><?= $ssoT('sso_settings.allowed_tenants') ?></label>
                            <textarea class="form-control font-monospace" id="sso-tenants" name="allowed_tenants" rows="2"><?= $ssoEsc(implode("\n", array_diff((array) $ssoSettings['allowed_tenants'], [$ssoSettings['tenant_id']]))) ?></textarea>
                            <p class="form-hint"><?= $ssoT('sso_settings.allowed_tenants_hint') ?></p>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sso-label"><?= $ssoT('sso_settings.button_label') ?></label>
                            <input class="form-control" id="sso-label" name="button_label" type="text" maxlength="60" value="<?= $ssoEsc($ssoSettings['button_label']) ?>">
                        </div>
                    </div>
                </div>

                <div class="card-header border-top">
                    <h2 class="card-title"><?= $ssoT('sso_settings.accounts.heading') ?></h2>
                </div>
                <div class="card-body">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="auto_link" value="1"<?= $ssoChecked('auto_link') ?>>
                        <span class="form-check-label"><?= $ssoT('sso_settings.auto_link') ?></span>
                    </label>
                    <p class="form-hint"><?= $ssoT('sso_settings.auto_link_hint') ?></p>
                    <div class="mb-3">
                        <label class="form-label" for="sso-domains"><?= $ssoT('sso_settings.allowed_domains') ?></label>
                        <textarea class="form-control" id="sso-domains" name="allowed_domains" rows="2"><?= $ssoEsc(implode("\n", (array) $ssoSettings['allowed_domains'])) ?></textarea>
                    </div>
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="auto_provision" value="1"<?= $ssoChecked('auto_provision') ?>>
                        <span class="form-check-label"><?= $ssoT('sso_settings.auto_provision') ?></span>
                    </label>
                    <p class="form-hint"><?= $ssoT('sso_settings.auto_provision_hint') ?></p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="sso-default-role"><?= $ssoT('sso_settings.default_role') ?></label>
                            <select class="form-select" id="sso-default-role" name="default_role">
                                <?php foreach ($ssoRoles as $roleKey => $roleLabel): if ((string) $roleKey === 'admin') { continue; } ?>
                                    <option value="<?= $ssoEsc($roleKey) ?>"<?= (string) $roleKey === $ssoSettings['default_role'] ? ' selected' : '' ?>><?= $ssoEsc($roleLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sso-local-login"><?= $ssoT('sso_settings.local_login') ?></label>
                            <select class="form-select" id="sso-local-login" name="local_login">
                                <?php foreach (EntraSettings::LOCAL_LOGIN_MODES as $mode): ?>
                                    <option value="<?= $ssoEsc($mode) ?>"<?= $mode === $ssoSettings['local_login'] ? ' selected' : '' ?>><?= $ssoT('sso_settings.local_login.' . $mode) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="form-hint"><?= $ssoT('sso_settings.local_login_hint') ?></p>
                        </div>
                    </div>
                    <label class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="mfa_from_amr" value="1"<?= $ssoChecked('mfa_from_amr') ?>>
                        <span class="form-check-label"><?= $ssoT('sso_settings.mfa_from_amr') ?></span>
                    </label>
                    <p class="form-hint mb-0"><?= $ssoT('sso_settings.mfa_from_amr_hint') ?></p>
                </div>

                <div class="card-header border-top">
                    <h2 class="card-title"><?= $ssoT('sso_settings.mapping.heading') ?></h2>
                </div>
                <div class="card-body">
                    <p class="text-secondary"><?= $ssoT('sso_settings.mapping.intro') ?></p>
                    <div class="table-responsive">
                        <table class="table table-sm table-vcenter" data-sso-mapping>
                            <thead>
                                <tr>
                                    <th scope="col"><?= $ssoT('sso_settings.mapping.type') ?></th>
                                    <th scope="col"><?= $ssoT('sso_settings.mapping.value') ?></th>
                                    <th scope="col"><?= $ssoT('sso_settings.mapping.role') ?></th>
                                    <th scope="col" class="w-1"><span class="visually-hidden"><?= $ssoT('sso_settings.mapping.remove') ?></span></th>
                                </tr>
                            </thead>
                            <tbody data-sso-mapping-body>
                                <?php foreach ($ssoRules as $rule): ?>
                                    <?= $ssoRow((array) $rule) ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <template data-sso-mapping-template><?= $ssoRow(['type' => 'role', 'value' => '', 'role' => (string) $ssoSettings['default_role']]) ?></template>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-sso-mapping-add><?= $ssoT('sso_settings.mapping.add') ?></button>
                    <div class="mt-3">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="sync_roles" value="1"<?= $ssoChecked('sync_roles') ?>>
                            <span class="form-check-label"><?= $ssoT('sso_settings.sync_roles') ?></span>
                        </label>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="allow_admin_mapping" value="1"<?= $ssoChecked('allow_admin_mapping') ?>>
                            <span class="form-check-label"><?= $ssoT('sso_settings.allow_admin_mapping') ?></span>
                        </label>
                        <p class="form-hint mb-0"><?= $ssoT('sso_settings.mapping.hint') ?></p>
                    </div>
                </div>
                <div class="card-footer text-end">
                    <button class="btn btn-primary" type="submit"><?= $ssoEsc((string) __('Save Changes')) ?></button>
                </div>
            </form>

            <form method="post" action="/admin/settings?tab=sso" class="card mt-3" id="sso-test">
                <input type="hidden" name="csrf_token" value="<?= $ssoEsc($ssoTestCsrfToken) ?>">
                <input type="hidden" name="form" value="sso_test">
                <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
                    <p class="text-secondary mb-0"><?= $ssoT('sso_settings.test.intro') ?></p>
                    <button class="btn btn-outline-primary" type="submit"><?= $ssoT('sso_settings.test.button') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
