<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use CMS\Auth;
use CMS\Security;

const CMS_ADMIN_POST_TAGS_WRITE_CAPABILITY = 'edit_all_posts';
const CMS_ADMIN_POST_TAGS_FORM_SESSION_KEY = 'admin_post_tags_form';

function cms_admin_post_tags_create_module(): object
{
    $moduleFile = realpath(__DIR__ . '/modules/posts/PostsModule.php') ?: __DIR__ . '/modules/posts/PostsModule.php';
    if (!is_file($moduleFile)) {
        throw new RuntimeException('PostsModule-Datei wurde nicht gefunden.');
    }

    if (!class_exists('PostsModule', false)) {
        require $moduleFile;
    }

    if (!class_exists('PostsModule', false)) {
        throw new RuntimeException('PostsModule konnte für die Tag-Verwaltung nicht geladen werden.');
    }

    return new PostsModule();
}

/**
 * @param array<string,mixed> $post
 * @param array<int,string> $details
 */
function cms_admin_post_tags_store_form_state(array $post, string $message, array $details = []): void
{
    $_SESSION[CMS_ADMIN_POST_TAGS_FORM_SESSION_KEY] = [
        'alert' => [
            'type' => 'danger',
            'message' => $message,
            'details' => array_values(array_filter(array_map('strval', $details), static fn(string $detail): bool => trim($detail) !== '')),
        ],
        'values' => [
            'tag_id' => max(0, (int) ($post['tag_id'] ?? 0)),
            'tag_name' => (string) ($post['tag_name'] ?? ''),
            'tag_slug' => (string) ($post['tag_slug'] ?? ''),
            'tag_slug_en' => (string) ($post['tag_slug_en'] ?? ''),
            'tag_description' => mb_substr((string) ($post['tag_description'] ?? ''), 0, 2000),
        ],
    ];
}

function cms_admin_post_tags_target_url(?int $editId = null): string
{
    if ($editId !== null && $editId > 0) {
        return '/admin/post-tags?edit=' . $editId;
    }

    return '/admin/post-tags';
}

function cms_admin_post_tags_redirect(?int $editId = null): never
{
    header('Location: ' . cms_admin_post_tags_target_url($editId));
    exit;
}

if (!Auth::instance()->isAdmin() || !Auth::instance()->hasCapability(CMS_ADMIN_POST_TAGS_WRITE_CAPABILITY)) {
    header('Location: /');
    exit;
}

require_once __DIR__ . '/modules/posts/PostsModule.php';

$module = cms_admin_post_tags_create_module();
$alert = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $postToken = (string) ($_POST['csrf_token'] ?? '');
    $redirectEditId = 0;

    if (!Security::instance()->verifyToken($postToken, 'admin_post_tags')) {
        $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => 'Sicherheitstoken ungültig. Bitte erneut versuchen.'];
        cms_admin_post_tags_redirect();
    }

    switch ($action) {
        case 'save_tag':
            $redirectEditId = max(0, (int) ($_POST['tag_id'] ?? 0));
            $result = $module->saveTag($_POST);
            break;
        case 'delete_tag':
            $result = $module->deleteTag(
                (int) ($_POST['tag_id'] ?? 0),
                (int) ($_POST['replacement_tag_id'] ?? 0)
            );
            break;
        case 'bulk_delete_tags':
            $result = $module->bulkDeleteTags(
                (array) ($_POST['tag_ids'] ?? []),
                (int) ($_POST['bulk_replacement_tag_id'] ?? 0)
            );
            break;
        default:
            $result = ['success' => false, 'error' => 'Unbekannte Aktion.'];
            break;
    }

    if ($action === 'save_tag' && empty($result['success'])) {
        cms_admin_post_tags_store_form_state(
            $_POST,
            (string) ($result['message'] ?? $result['error'] ?? 'Tag konnte nicht gespeichert werden.'),
            is_array($result['details'] ?? null) ? $result['details'] : []
        );

        cms_admin_post_tags_redirect($redirectEditId > 0 ? $redirectEditId : null);
    }

    $_SESSION['admin_alert'] = [
        'type' => !empty($result['success']) ? 'success' : 'danger',
        'message' => (string) ($result['message'] ?? $result['error'] ?? 'Aktion abgeschlossen.'),
        'details' => is_array($result['details'] ?? null) ? $result['details'] : [],
    ];

    cms_admin_post_tags_redirect(!empty($result['success']) ? null : ($redirectEditId > 0 ? $redirectEditId : null));
}

if (!empty($_SESSION['admin_alert'])) {
    $alert = $_SESSION['admin_alert'];
    unset($_SESSION['admin_alert']);
}

$formAlert = null;
$formValues = [];
if (!empty($_SESSION[CMS_ADMIN_POST_TAGS_FORM_SESSION_KEY]) && is_array($_SESSION[CMS_ADMIN_POST_TAGS_FORM_SESSION_KEY])) {
    $formState = $_SESSION[CMS_ADMIN_POST_TAGS_FORM_SESSION_KEY];
    $formAlert = is_array($formState['alert'] ?? null) ? $formState['alert'] : null;
    $formValues = is_array($formState['values'] ?? null) ? $formState['values'] : [];
    unset($_SESSION[CMS_ADMIN_POST_TAGS_FORM_SESSION_KEY]);
}

$csrfToken = Security::instance()->generateToken('admin_post_tags');
$editTagId = max(0, (int) ($_GET['edit'] ?? 0));
$data = $module->getTagAdminData($editTagId);
$editTag = $data['editTag'] ?? null;

if ($editTagId > 0 && !is_array($editTag)) {
    $_SESSION['admin_alert'] = ['type' => 'danger', 'message' => 'Der angeforderte Tag existiert nicht mehr. Bitte Liste neu laden.'];
    cms_admin_post_tags_redirect();
}

$pageTitle = 'Beitrags-Tags';
$activePage = 'post-tags';

require __DIR__ . '/partials/header.php';
require __DIR__ . '/partials/sidebar.php';
require __DIR__ . '/views/posts/tags.php';
require __DIR__ . '/partials/footer.php';
