<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Beitragseditor-API (JSON) – Route: /admin/post-editor-api
 *
 * GET  ?action=revisions&post_id=…                Revisionsliste
 * GET  ?action=compare&post_id=…&revision_id=…    Vergleich mit der Vorgängerversion
 * GET  ?action=token                               frisches CSRF-Token (nach Ablauf)
 * POST action=autosave (Formulardaten des Editors) Autosave
 * POST action=restore, post_id, revision_id        Version wiederherstellen
 *
 * Alle Aktionen: angemeldete Admin-Rolle + Beitrags-Capability + Besitzprüfung im PostsModule;
 * schreibende Aktionen zusätzlich mit CSRF-Token (Aktion admin_post_editor).
 */

use CMS\Auth;
use CMS\Logger;
use CMS\Security;

\CMS\CacheManager::instance()->sendResponseHeaders('private');
header('Content-Type: application/json; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow, noarchive');

$cmsPostEditorApiRespond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};

$auth = Auth::instance();
$security = Security::instance();
$csrfAction = 'admin_post_editor';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($method === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? ''))));

if (!$auth->isLoggedIn() || !$auth->isAdmin()) {
    $cmsPostEditorApiRespond(403, ['success' => false, 'error' => 'Keine Berechtigung für den Beitragseditor.']);
}

$canWrite = $auth->hasCapability('edit_all_posts')
    || $auth->hasCapability('edit_own_posts')
    || $auth->hasCapability('posts.edit');
$canRead = $canWrite || $auth->hasCapability('posts.view');

$readActions = ['revisions', 'compare', 'token'];
$writeActions = ['autosave', 'restore'];

if (in_array($action, $readActions, true)) {
    if ($method !== 'GET') {
        $cmsPostEditorApiRespond(405, ['success' => false, 'error' => 'Diese Aktion erlaubt nur GET.']);
    }
    if (!$canRead) {
        $cmsPostEditorApiRespond(403, ['success' => false, 'error' => 'Keine Berechtigung für Beitragsrevisionen.']);
    }
} elseif (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $cmsPostEditorApiRespond(405, ['success' => false, 'error' => 'Diese Aktion erlaubt nur POST.']);
    }
    if (!$canWrite) {
        $cmsPostEditorApiRespond(403, ['success' => false, 'error' => 'Keine Berechtigung zum Bearbeiten von Beiträgen.']);
    }
    if (!$security->verifyPersistentToken((string) ($_POST['csrf_token'] ?? ''), $csrfAction)) {
        $cmsPostEditorApiRespond(403, ['success' => false, 'code' => 'csrf', 'error' => 'Sicherheitstoken ist abgelaufen. Bitte erneut versuchen.']);
    }
} else {
    $cmsPostEditorApiRespond(400, ['success' => false, 'error' => 'Unbekannte Aktion.']);
}

if ($action === 'token') {
    $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && $fetchSite !== 'same-origin') {
        $cmsPostEditorApiRespond(403, ['success' => false, 'error' => 'Token nur für Anfragen derselben Herkunft.']);
    }

    $cmsPostEditorApiRespond(200, ['success' => true, 'token' => $security->generateToken($csrfAction)]);
}

$postId = (int) ($method === 'POST' ? ($_POST['post_id'] ?? ($_POST['id'] ?? 0)) : ($_GET['post_id'] ?? 0));
if ($postId <= 0) {
    $cmsPostEditorApiRespond(400, ['success' => false, 'error' => 'Bitte den Beitrag zuerst speichern.']);
}

$userId = (int) ($auth->getCurrentUser()->id ?? 0);

try {
    require_once __DIR__ . '/modules/posts/PostsModule.php';
    $module = new PostsModule();

    switch ($action) {
        case 'revisions':
            $cmsPostEditorApiRespond(200, ['success' => true, 'revisions' => $module->listPostRevisions($postId)]);

        case 'compare':
            $result = $module->comparePostRevision($postId, (int) ($_GET['revision_id'] ?? 0));
            $cmsPostEditorApiRespond(!empty($result['success']) ? 200 : 404, $result);

        case 'autosave':
            $post = $_POST;
            unset($post['action'], $post['csrf_token'], $post['_action'], $post['post_id']);
            $post['id'] = $postId;
            $result = $module->autosave($post, $userId);
            if (!empty($result['success'])) {
                $result['saved_at'] = date('c');
                $result['saved_at_label'] = date('H:i');
                $result['revisions'] = $module->listPostRevisions($postId);
            }
            $cmsPostEditorApiRespond(!empty($result['success']) ? 200 : 422, $result);

        case 'restore':
            $result = $module->restorePostRevision($postId, (int) ($_POST['revision_id'] ?? 0), $userId);
            if (!empty($result['success']) && empty($result['unchanged'])) {
                // Der Editor lädt nach der Wiederherstellung neu; die Meldung erscheint dann als Admin-Hinweis.
                $_SESSION['admin_alert'] = ['type' => 'success', 'message' => (string) ($result['message'] ?? 'Version wiederhergestellt.')];
            }
            $cmsPostEditorApiRespond(!empty($result['success']) ? 200 : 422, $result);
    }
} catch (\Throwable $e) {
    Logger::instance()->withChannel('admin.posts')->error('Beitragseditor-API fehlgeschlagen.', [
        'action' => $action,
        'post_id' => $postId,
        'exception_class' => get_debug_type($e),
        'exception' => $e->getMessage(),
    ]);
}

$cmsPostEditorApiRespond(500, ['success' => false, 'error' => 'Interner Fehler – bitte Logs prüfen.']);
