<?php
declare(strict_types=1);

/**
 * Sicherheits-Audit – Entry Point
 * Route: /admin/security-audit
 */

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Security;
use CMS\Services\CoreModuleService;

const CMS_ADMIN_SECURITY_AUDIT_ALLOWED_ACTIONS = ['run_audit', 'clear_log', 'save_log_retention'];

function cms_admin_security_audit_normalize_action(mixed $action): string
{
    $normalizedAction = strtolower(trim((string)$action));

    return in_array($normalizedAction, CMS_ADMIN_SECURITY_AUDIT_ALLOWED_ACTIONS, true) ? $normalizedAction : '';
}

if (!Auth::instance()->isAdmin()
    || !Auth::instance()->hasCapability('manage_settings')
    || (class_exists(CoreModuleService::class) && !CoreModuleService::getInstance()->isAdminPageEnabled('security-audit'))
) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/security/SecurityAuditModule.php';
$module    = new SecurityAuditModule();
$alert     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::instance()->verifyToken($_POST['csrf_token'] ?? '', 'admin_sec_audit')) {
        $alert = ['type' => 'danger', 'message' => 'Sicherheitstoken ungültig.'];
    } else {
        $action = cms_admin_security_audit_normalize_action($_POST['action'] ?? '');
        switch ($action) {
            case 'run_audit':
                $result = $module->runAudit();
                break;
            case 'clear_log':
                $result = $module->clearLog();
                break;
            case 'save_log_retention':
                $result = $module->saveLogRetention($_POST);
                break;
            default:
                $result = ['success' => false, 'error' => 'Unbekannte Aktion.'];
        }
        $_SESSION['admin_alert'] = ['type' => $result['success'] ? 'success' : 'danger', 'message' => $result['message'] ?? $result['error'] ?? ''];
        header('Location: /admin/security-audit');
        exit;
    }
    $csrfToken = Security::instance()->generateToken('admin_sec_audit');
}

if (isset($_SESSION['admin_alert'])) {
    $alert = $_SESSION['admin_alert'];
    unset($_SESSION['admin_alert']);
}

$csrfToken  = Security::instance()->generateToken('admin_sec_audit');
$pageTitle  = 'Sicherheits-Audit';
$activePage = 'security-audit';
$data       = $module->getData();

require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/partials/sidebar.php';
require_once __DIR__ . '/views/security/audit.php';
require_once __DIR__ . '/partials/footer.php';
