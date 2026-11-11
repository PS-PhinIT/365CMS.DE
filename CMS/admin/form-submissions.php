<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulareingänge – Entry Point
 * Route: /admin/form-submissions
 *
 * ?view=<id>             Detailansicht (neu → gelesen)
 * ?export=csv[&Filter]   CSV-Export der gefilterten Eingänge
 * ?file=<id>             hochgeladene Datei eines Eingangs (privater Speicher)
 * ?tab=privacy           DSGVO-Exportdateien, ?privacy_export=<name> lädt eine Datei
 *
 * Endgültiges Löschen und das Entfernen von DSGVO-Exportdateien verlangen zusätzlich `manage_forms`.
 */

use CMS\Auth;

const CMS_ADMIN_FORM_SUBMISSIONS_CAPABILITY = 'view_form_submissions';
const CMS_ADMIN_FORM_SUBMISSIONS_MANAGE_CAPABILITY = 'manage_forms';

function cms_admin_form_submissions_can_access(): bool
{
    return Auth::instance()->isAdmin()
        && Auth::instance()->hasCapability(CMS_ADMIN_FORM_SUBMISSIONS_CAPABILITY);
}

function cms_admin_form_submissions_can_manage(): bool
{
    return cms_admin_form_submissions_can_access()
        && Auth::instance()->hasCapability(CMS_ADMIN_FORM_SUBMISSIONS_MANAGE_CAPABILITY);
}

if (!cms_admin_form_submissions_can_access()) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/forms/FormSubmissionsModule.php';

// Datei-Abrufe (nur GET, ohne Zustandsänderung) vor der Seitenausgabe.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $cmsFormSubmissionsModule = new FormSubmissionsModule();
    if ($cmsFormSubmissionsModule->isAvailable()) {
        if (($_GET['export'] ?? '') === 'csv') {
            $cmsFormSubmissionsModule->streamCsv($_GET);
        }
        if (isset($_GET['file'])) {
            $cmsFormSubmissionsModule->streamFile((int) $_GET['file']);
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('forms.inbox.file_not_found')];
            header('Location: /admin/form-submissions', true, 303);
            exit;
        }
        if (isset($_GET['privacy_export'])) {
            $cmsFormSubmissionsModule->streamPrivacyExport(is_scalar($_GET['privacy_export']) ? (string) $_GET['privacy_export'] : '');
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('forms.privacy.export_not_found')];
            header('Location: /admin/form-submissions?tab=privacy', true, 303);
            exit;
        }
    }
    unset($cmsFormSubmissionsModule);
}

/**
 * @return array<string, mixed>
 */
function cms_admin_form_submissions_request_context(FormSubmissionsModule $module): array
{
    $tab = in_array($_GET['tab'] ?? '', FormSubmissionsModule::TABS, true) ? (string) $_GET['tab'] : 'inbox';
    $assets = ['css' => [cms_asset_url('css/admin-forms.css')], 'js' => [cms_asset_url('js/admin-form-submissions.js')]];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        return ['section' => $tab, 'data' => []];
    }

    $canManage = cms_admin_form_submissions_can_manage();
    if ($tab === 'privacy') {
        return ['section' => 'privacy', 'page_assets' => $assets, 'data' => $module->getPrivacyData($canManage)];
    }

    $viewId = (int) ($_GET['view'] ?? 0);
    if ($viewId > 0) {
        $data = $module->getDetailData($viewId, $canManage);
        if ($data === null) {
            $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => __('forms.admin.submission_not_found')];
            header('Location: /admin/form-submissions', true, 303);
            exit;
        }

        return ['section' => 'inbox', 'page_assets' => $assets, 'data' => $data];
    }

    return ['section' => 'inbox', 'page_assets' => $assets, 'data' => $module->getInboxData($_GET, $canManage)];
}

$sectionPageConfig = [
    'section' => 'inbox',
    'route_path' => '/admin/form-submissions',
    'view_file' => __DIR__ . '/views/forms/submissions.php',
    'page_title' => __('forms.inbox.page_title'),
    'active_page' => 'form-submissions',
    'csrf_action' => 'admin_form_submissions',
    'module_factory' => static fn (): FormSubmissionsModule => new FormSubmissionsModule(),
    'access_checker' => static fn (): bool => cms_admin_form_submissions_can_access(),
    'access_denied_route' => '/',
    'request_context_resolver' => static fn (FormSubmissionsModule $module): array => cms_admin_form_submissions_request_context($module),
    'redirect_path_resolver' => static function ($module, string $section, mixed $result = null): string {
        if (is_array($result) && is_string($result['__redirect'] ?? null)) {
            return (string) $result['__redirect'];
        }

        return FormSubmissionsModule::url(['tab' => $section === 'privacy' ? 'privacy' : null]);
    },
    'post_handler' => static function (FormSubmissionsModule $module, string $section, array $post): array {
        if (!cms_admin_form_submissions_can_access()) {
            return ['success' => false, 'error' => __('forms.admin.error_forbidden')];
        }

        return $module->handlePost($post, (int) (Auth::instance()->currentUser()->id ?? 0), cms_admin_form_submissions_can_manage());
    },
    'invalid_token_message' => __('forms.admin.error_token'),
    'unknown_action_message' => __('forms.inbox.error_unknown_action'),
];

require __DIR__ . '/partials/section-page-shell.php';
