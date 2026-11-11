<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Einstellungen › Login & Sperren – Erweiterungs-Tab (/admin/settings?tab=security)
 *
 * Fehlversuche bis zur Sperre (3–20) und Sperrzeit (60–3600 s) für alle Login-Wege (Admin, Mitglieder,
 * Passwort-Zurücksetzung). Leere Felder = Konstante aus config/app.php. Wirksamer Wert und Quelle werden
 * angezeigt; Speichern mit CSRF, `manage_settings` und Audit-Log (`LoginThrottleSettings::save()`).
 */

use CMS\Auth;
use CMS\Security;
use CMS\Services\LoginThrottleSettings;

const CMS_ADMIN_SECURITY_SETTINGS_CAPABILITY = 'manage_settings';
const CMS_ADMIN_SECURITY_SETTINGS_CSRF_ACTION = 'admin_security_settings';

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability(CMS_ADMIN_SECURITY_SETTINGS_CAPABILITY)) {
    header('Location: /');
    exit;
}

$securityAlert = $_SESSION['admin_alert'] ?? null;
unset($_SESSION['admin_alert']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $parse = static function (mixed $raw): int|false|null {
        $raw = is_scalar($raw) ? trim((string) $raw) : '';
        if ($raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return $value === false ? false : (int) $value;
    };

    if (!Security::instance()->verifyToken((string) ($_POST['csrf_token'] ?? ''), CMS_ADMIN_SECURITY_SETTINGS_CSRF_ACTION)) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('security_settings.error.csrf')];
    } else {
        $useConstants = !empty($_POST['use_constants']);
        $attempts = $useConstants ? null : $parse($_POST['max_login_attempts'] ?? '');
        $timeout = $useConstants ? null : $parse($_POST['login_timeout'] ?? '');

        if ($attempts === false || ($attempts !== null && !LoginThrottleSettings::attemptsInRange($attempts))) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => sprintf(__('security_settings.error.attempts'), LoginThrottleSettings::MIN_ATTEMPTS, LoginThrottleSettings::MAX_ATTEMPTS)];
        } elseif ($timeout === false || ($timeout !== null && !LoginThrottleSettings::timeoutInRange($timeout))) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => sprintf(__('security_settings.error.timeout'), LoginThrottleSettings::MIN_TIMEOUT, LoginThrottleSettings::MAX_TIMEOUT)];
        } elseif (!LoginThrottleSettings::save($attempts, $timeout, (int) (Auth::instance()->currentUser()->id ?? 0))) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('security_settings.error.save')];
        } else {
            $_SESSION['admin_alert'] = ['type' => 'success', 'message' => __('security_settings.saved')];
        }
    }

    header('Location: /admin/settings?tab=security', true, 303);
    exit;
}

$securityThresholds = LoginThrottleSettings::describe();
$securityCsrfToken = Security::instance()->generateToken(CMS_ADMIN_SECURITY_SETTINGS_CSRF_ACTION);
$pageTitle = __('security_settings.title');
$activePage = 'settings-security';
$settingsTabsActive = 'security';

require __DIR__ . '/../partials/header.php';
require __DIR__ . '/../partials/sidebar.php';
require __DIR__ . '/../views/settings/security.php';
require __DIR__ . '/../partials/footer.php';
