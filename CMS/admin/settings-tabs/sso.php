<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Einstellungen › SSO – Erweiterungs-Tab (/admin/settings?tab=sso), 4.1.0 Bereich 1c.
 *
 * Microsoft Entra ID über OpenID Connect: Tenant, Client-ID, Client-Secret (nur verschlüsselt gespeichert, nie
 * zurückgegeben), Kontoverknüpfung, Provisioning, Rollen-Mapping, MFA aus `amr`, lokale Anmeldung. Zwei Formulare
 * mit eigenem CSRF-Token: Speichern (Audit `sso_settings_saved`, ohne Secret) und „Verbindung prüfen“.
 * Capability `security.sso` (Admins haben sie immer).
 */

use CMS\AuditLogger;
use CMS\Auth;
use CMS\Auth\SSO\EntraIdProvider;
use CMS\Auth\SSO\EntraSettings;
use CMS\Security;
use CMS\Services\SettingsService;
use CMS\Services\UserService;

const CMS_ADMIN_SSO_CAPABILITY = 'security.sso';
const CMS_ADMIN_SSO_CSRF_ACTION = 'admin_sso_settings';
const CMS_ADMIN_SSO_TEST_CSRF_ACTION = 'admin_sso_test';

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability(CMS_ADMIN_SSO_CAPABILITY)) {
    header('Location: /');
    exit;
}

$ssoAlert = $_SESSION['admin_alert'] ?? null;
unset($_SESSION['admin_alert']);
$ssoRoles = UserService::getInstance()->getAvailableRoles();
$ssoKnownRoles = array_map('strval', array_keys($ssoRoles));
$ssoMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($ssoMethod === 'POST' && (string) ($_POST['form'] ?? '') === 'sso_settings') {
    if (!Security::instance()->verifyToken((string) ($_POST['csrf_token'] ?? ''), CMS_ADMIN_SSO_CSRF_ACTION)) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('security_settings.error.csrf')];
    } else {
        $input = $_POST;
        // Mapping-Tabelle: parallele Felder je Zeile (Reihenfolge = Priorität).
        $types = is_array($_POST['mapping_type'] ?? null) ? array_values($_POST['mapping_type']) : [];
        $values = is_array($_POST['mapping_value'] ?? null) ? array_values($_POST['mapping_value']) : [];
        $targets = is_array($_POST['mapping_role'] ?? null) ? array_values($_POST['mapping_role']) : [];
        $input['role_mapping'] = [];
        foreach ($types as $index => $type) {
            $input['role_mapping'][] = [
                'type' => is_string($type) ? $type : '',
                'value' => is_string($values[$index] ?? null) ? $values[$index] : '',
                'role' => is_string($targets[$index] ?? null) ? $targets[$index] : '',
            ];
        }
        foreach (['enabled', 'auto_link', 'auto_provision', 'sync_roles', 'allow_admin_mapping', 'mfa_from_amr'] as $flag) {
            $input[$flag] = !empty($_POST[$flag]) ? '1' : '0';
        }

        try {
            $saved = EntraSettings::save($input, $ssoKnownRoles);
            $summary = $saved;
            unset($summary['button_label']);
            AuditLogger::instance()->log(
                AuditLogger::CAT_SECURITY,
                'sso_settings_saved',
                'Entra-ID-Anmeldung: Einstellungen gespeichert',
                'settings',
                null,
                $summary + ['secret_changed' => trim((string) ($_POST['client_secret'] ?? '')) !== '' || !empty($_POST['clear_secret'])]
            );
            $_SESSION['admin_alert'] = $saved['enabled'] && !EntraSettings::isReady($saved)
                ? ['type' => 'warning', 'message' => __('sso_settings.saved_incomplete')]
                : ['type' => 'success', 'message' => __('sso_settings.saved')];
        } catch (\InvalidArgumentException) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('sso_settings.error.secret')];
        } catch (\Throwable) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('security_settings.error.save')];
        }
    }

    header('Location: /admin/settings?tab=sso', true, 303);
    exit;
}

if ($ssoMethod === 'POST' && (string) ($_POST['form'] ?? '') === 'sso_test') {
    if (!Security::instance()->verifyToken((string) ($_POST['csrf_token'] ?? ''), CMS_ADMIN_SSO_TEST_CSRF_ACTION)) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('security_settings.error.csrf')];
    } else {
        $testSettings = EntraSettings::load($ssoKnownRoles);
        if ($testSettings['tenant_id'] === '' || $testSettings['client_id'] === '' || !$testSettings['client_secret_set']) {
            $_SESSION['admin_alert'] = ['type' => 'warning', 'message' => __('sso_settings.test.incomplete')];
        } else {
            $test = (new EntraIdProvider($testSettings + ['client_secret' => EntraSettings::clientSecret()]))->testConnection(EntraSettings::redirectUri());
            $knownCodes = ['ok', 'discovery_failed', 'discovery_mismatch', 'invalid_client', 'unauthorized_client'];
            $message = (string) __('sso_settings.test.' . (in_array($test['code'], $knownCodes, true) ? $test['code'] : 'unexpected'));
            if (!$test['ok'] && $test['detail'] !== '') {
                $message .= ' (' . $test['detail'] . ')';
            }
            $_SESSION['admin_alert'] = ['type' => $test['ok'] ? 'success' : 'danger', 'message' => $message];
            AuditLogger::instance()->log(AuditLogger::CAT_SECURITY, 'sso_connection_test', 'Entra-ID-Anmeldung: Verbindung geprüft', 'settings', null, ['result' => $test['code']]);
        }
    }

    header('Location: /admin/settings?tab=sso', true, 303);
    exit;
}

$ssoSettings = EntraSettings::load($ssoKnownRoles);
$ssoRedirectUri = EntraSettings::redirectUri();
$ssoSecretDaysLeft = EntraSettings::secretDaysLeft($ssoSettings);
$ssoMailTenant = '';
try {
    $ssoMailTenant = EntraSettings::normalizeGuid(SettingsService::getInstance()->getString('mail', 'azure_tenant_id'));
} catch (\Throwable) {
}
$ssoCsrfToken = Security::instance()->generateToken(CMS_ADMIN_SSO_CSRF_ACTION);
$ssoTestCsrfToken = Security::instance()->generateToken(CMS_ADMIN_SSO_TEST_CSRF_ACTION);
$pageTitle = __('sso_settings.title');
$activePage = 'settings-sso';
$settingsTabsActive = 'sso';
$pageAssets = [
    'js' => [cms_asset_url('js/admin-sso-settings.js')],
];

require __DIR__ . '/../partials/header.php';
require __DIR__ . '/../partials/sidebar.php';
require __DIR__ . '/../views/settings/sso.php';
require __DIR__ . '/../partials/footer.php';
