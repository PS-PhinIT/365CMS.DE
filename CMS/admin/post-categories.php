<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Security;

const CMS_ADMIN_POST_CATEGORIES_WRITE_CAPABILITY = 'edit_all_posts';
const CMS_ADMIN_POST_CATEGORIES_FORM_SESSION_KEY = 'admin_post_categories_form';

function cms_admin_post_categories_create_module(): object
{
    $moduleFile = realpath(__DIR__ . '/modules/posts/PostsModule.php') ?: __DIR__ . '/modules/posts/PostsModule.php';
    if (!is_file($moduleFile)) {
        throw new RuntimeException('PostsModule-Datei wurde nicht gefunden.');
    }

    if (!class_exists('PostsModule', false)) {
        require $moduleFile;
    }

    if (!class_exists('PostsModule', false)) {
        throw new RuntimeException('PostsModule konnte für die Kategorien-Verwaltung nicht geladen werden.');
    }

    return new PostsModule();
}

function cms_admin_post_categories_target_url(?int $editId = null): string
{
    if ($editId !== null && $editId > 0) {
        return '/admin/post-categories?edit=' . $editId;
    }

    return '/admin/post-categories';
}

/**
 * @param array<string,mixed> $post
 * @param array<int,string> $details
 */
function cms_admin_post_categories_store_form_state(array $post, string $message, array $details = []): void
{
    $_SESSION[CMS_ADMIN_POST_CATEGORIES_FORM_SESSION_KEY] = [
        'alert' => [
            'type' => 'danger',
            'message' => $message,
            'details' => array_values(array_filter(array_map('strval', $details), static fn(string $detail): bool => trim($detail) !== '')),
        ],
        'values' => [
            'cat_id' => max(0, (int) ($post['cat_id'] ?? 0)),
            'cat_name' => (string) ($post['cat_name'] ?? ''),
            'cat_slug' => (string) ($post['cat_slug'] ?? ''),
            'cat_slug_en' => (string) ($post['cat_slug_en'] ?? ''),
            'cat_description' => mb_substr((string) ($post['cat_description'] ?? ''), 0, 2000),
            'parent_id' => max(0, (int) ($post['parent_id'] ?? 0)),
            'replacement_category_id' => max(0, (int) ($post['replacement_category_id'] ?? 0)),
        ],
    ];
}

function cms_admin_post_categories_redirect(?int $editId = null): never
{
    header('Location: ' . cms_admin_post_categories_target_url($editId));
    exit;
}

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability(CMS_ADMIN_POST_CATEGORIES_WRITE_CAPABILITY)) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/posts/PostsModule.php';

$module = cms_admin_post_categories_create_module();
$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postToken = (string) ($_POST['csrf_token'] ?? '');
    $redirectEditId = 0;

    if (!Security::instance()->verifyToken($postToken, 'admin_post_categories')) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => 'Sicherheitstoken ungültig. Bitte erneut versuchen.'];
        cms_admin_post_categories_redirect();
    }

    switch ($action) {
        case 'save_category':
            $redirectEditId = max(0, (int) ($_POST['cat_id'] ?? 0));
            $result = $module->saveCategory($_POST);
            break;
        case 'delete_category':
            $result = $module->deleteCategory(
                (int) ($_POST['cat_id'] ?? 0),
                (int) ($_POST['replacement_category_id'] ?? 0)
            );
            break;
        case 'delete_categories_with_replacement':
            $result = $module->deleteCategoriesWithStoredReplacement();
            break;
        case 'bulk_delete_categories':
            $result = $module->bulkDeleteCategories(
                (array) ($_POST['category_ids'] ?? []),
                (int) ($_POST['bulk_replacement_category_id'] ?? 0)
            );
            break;
        default:
            $result = ['success' => false, 'error' => 'Unbekannte Aktion.'];
            break;
    }

    if ($action === 'save_category' && empty($result['success'])) {
        cms_admin_post_categories_store_form_state(
            $_POST,
            (string) ($result['message'] ?? $result['error'] ?? 'Kategorie konnte nicht gespeichert werden.'),
            is_array($result['details'] ?? null) ? $result['details'] : []
        );

        cms_admin_post_categories_redirect($redirectEditId > 0 ? $redirectEditId : null);
    }

    $_SESSION['admin_alert'] = [
        'type' => !empty($result['success']) ? 'success' : 'danger',
        'message' => (string) ($result['message'] ?? $result['error'] ?? 'Aktion abgeschlossen.'),
        'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
    ];

    cms_admin_post_categories_redirect();
}

if (!empty($_SESSION['admin_alert'])) {
    $alert = $_SESSION['admin_alert'];
    unset($_SESSION['admin_alert']);
}

$formAlert = null;
$formValues = [];
if (!empty($_SESSION[CMS_ADMIN_POST_CATEGORIES_FORM_SESSION_KEY]) && is_array($_SESSION[CMS_ADMIN_POST_CATEGORIES_FORM_SESSION_KEY])) {
    $formState = $_SESSION[CMS_ADMIN_POST_CATEGORIES_FORM_SESSION_KEY];
    $formAlert = is_array($formState['alert'] ?? null) ? $formState['alert'] : null;
    $formValues = is_array($formState['values'] ?? null) ? $formState['values'] : [];
    unset($_SESSION[CMS_ADMIN_POST_CATEGORIES_FORM_SESSION_KEY]);
}

$csrfToken = Security::instance()->generateToken('admin_post_categories');
$data = $module->getCategoryAdminData();
$editCategoryId = max(0, (int) ($_GET['edit'] ?? 0));
$editCategory = null;

if ($editCategoryId > 0) {
    foreach (($data['categories'] ?? []) as $category) {
        if ((int) ($category['id'] ?? 0) === $editCategoryId) {
            $editCategory = $category;
            break;
        }
    }

    if ($editCategory === null) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => 'Die angeforderte Kategorie existiert nicht mehr. Bitte Liste neu laden.'];
        cms_admin_post_categories_redirect();
    }
}

$pageTitle = 'Beitrags-Kategorien';
$activePage = 'post-categories';

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/views/posts/categories.php';
require __DIR__ . '/partials/footer.php';
