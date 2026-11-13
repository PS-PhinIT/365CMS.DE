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
 * POST action=block_preview, type, data, tunes      Server-Vorschau eines registrierten Core-Blocks
 *                                                  (EditorJsBlockRegistry, Kontext „preview“); data/tunes als JSON
 * GET  ?action=form_choices                        aktive Formulare für den Block „Formular“ (Schlüssel, Titel)
 * GET  ?action=download_search[&q&category&type&ids] Downloads für den Block „Download“ (upload_downloads)
 * POST action=download_upload, file[, title, summary, category_id, version, properties[<schlüssel>], new_properties]
 *                                                  Datei hochladen und als Download anlegen (upload_downloads);
 *                                                  new_properties = JSON-Liste {label, type, value} legt neue
 *                                                  Eigenschaftsfelder an (nur manage_downloads, wie die Einstellungen)
 *
 * Alle Aktionen: angemeldete Admin-Rolle + Beitrags-Capability + Besitzprüfung im PostsModule;
 * schreibende Aktionen zusätzlich mit CSRF-Token (Aktion admin_post_editor). `token` und `block_preview`
 * sowie die Block-Aktionen (`form_choices`) stehen auch dem Seiteneditor offen (`manage_pages`) und brauchen
 * keine Beitrags-ID.
 */

use CMS\Auth;
use CMS\Logger;
use CMS\Security;
use CMS\Services\EditorJs\EditorJsBlockRegistry;

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
// Seiteneditor: nur Token-Erneuerung und Block-Vorschau.
$canEditPages = $auth->hasCapability('manage_pages');

$readActions = ['revisions', 'compare', 'token', 'form_choices', 'download_search'];
$writeActions = ['autosave', 'restore', 'block_preview', 'download_upload'];
$pageEditorActions = ['token', 'block_preview', 'form_choices', 'download_search', 'download_upload'];
// Download-Block: Auswählen und Hochladen erfordern zusätzlich upload_downloads (oder manage_downloads).
$canUseDownloads = $auth->hasCapability('upload_downloads') || $auth->hasCapability('manage_downloads');
if (in_array($action, ['download_search', 'download_upload'], true) && !$canUseDownloads) {
    $cmsPostEditorApiRespond(403, ['success' => false, 'error' => 'Keine Berechtigung für Downloads.']);
}

if (in_array($action, $readActions, true)) {
    if ($method !== 'GET') {
        $cmsPostEditorApiRespond(405, ['success' => false, 'error' => 'Diese Aktion erlaubt nur GET.']);
    }
    if (!$canRead && !($canEditPages && in_array($action, $pageEditorActions, true))) {
        $cmsPostEditorApiRespond(403, ['success' => false, 'error' => 'Keine Berechtigung für Beitragsrevisionen.']);
    }
} elseif (in_array($action, $writeActions, true)) {
    if ($method !== 'POST') {
        $cmsPostEditorApiRespond(405, ['success' => false, 'error' => 'Diese Aktion erlaubt nur POST.']);
    }
    if (!$canWrite && !($canEditPages && in_array($action, $pageEditorActions, true))) {
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

if ($action === 'form_choices') {
    $formChoices = [];
    try {
        $formService = \CMS\Services\Forms\FormService::getInstance();
        if ($formService->isAvailable()) {
            $formService->ensureContactForm();
            foreach ($formService->choices(true) as $formKey => $formTitle) {
                $formChoices[] = ['key' => (string) $formKey, 'title' => (string) $formTitle];
            }
        }
    } catch (\Throwable $e) {
        Logger::instance()->withChannel('forms')->warning('Formularliste für den Editor-Block konnte nicht geladen werden.', ['exception' => $e->getMessage()]);
    }

    $cmsPostEditorApiRespond(200, [
        'success' => true,
        'forms' => $formChoices,
        'manage_url' => $auth->hasCapability('manage_forms') && is_file(ABSPATH . 'admin/forms.php') ? '/admin/forms' : '',
    ]);
}

if ($action === 'download_search') {
    $downloadService = \CMS\Services\Downloads\DownloadService::getInstance();
    if (!$downloadService->isAvailable()) {
        $cmsPostEditorApiRespond(200, ['success' => true, 'items' => [], 'total' => 0, 'categories' => [], 'file_types' => [], 'property_fields' => []]);
    }
    $searchFilters = [
        'search' => \CMS\Services\Downloads\DownloadService::plainText(is_scalar($_GET['q'] ?? null) ? (string) $_GET['q'] : '', 100),
        'category_id' => max(0, (int) ($_GET['category'] ?? 0)),
        'file_type' => is_scalar($_GET['type'] ?? null) ? (string) $_GET['type'] : '',
        'access_level' => is_scalar($_GET['access'] ?? null) ? (string) $_GET['access'] : '',
    ];
    if (isset($_GET['ids']) && is_scalar($_GET['ids'])) {
        $searchFilters = ['ids' => array_slice(array_map('intval', explode(',', (string) $_GET['ids'])), 0, 50)];
    }
    $found = $downloadService->list($searchFilters, 1, 30, 'updated');
    $foundItems = [];
    foreach ($found['items'] as $foundItem) {
        $foundItems[(int) $foundItem['id']] = $foundItem;
    }
    $foundVersions = $foundItems !== [] ? $downloadService->repository()->versionsForItems(array_keys($foundItems)) : [];
    $payload = [];
    foreach ($foundItems as $foundId => $foundItem) {
        $payload[] = [
            'id' => $foundId,
            'title' => (string) $foundItem['title'],
            'status' => (string) $foundItem['status'],
            'access' => (string) $foundItem['access_level'],
            'type' => (string) $foundItem['file_type'],
            'version' => (string) ($foundItem['current_version'] ?? ''),
            'size' => (int) ($foundItem['current_size'] ?? 0) > 0 ? \CMS\Services\Downloads\DownloadSecurity::formatBytes((int) $foundItem['current_size']) : '',
            'count' => (int) $foundItem['download_count'],
            'versions' => array_values(array_map(static fn (array $row): string => (string) $row['version'], $foundVersions[$foundId] ?? [])),
            'edit_url' => '/admin/downloads?action=edit&id=' . $foundId,
        ];
    }
    $cmsPostEditorApiRespond(200, [
        'success' => true,
        'items' => $payload,
        'total' => $found['total'],
        'categories' => array_map(static fn (array $term): array => ['id' => (int) $term['id'], 'name' => (string) $term['name']], $downloadService->categories(true)),
        'file_types' => $downloadService->repository()->usedFileTypes(),
        'can_manage' => $auth->hasCapability('manage_downloads'),
        // Für den Tab „Hochladen“: Eigenschaftsfelder der Verwaltung und Upload-Grenzen.
        'property_fields' => $downloadService->settings()->propertyFields(),
        'allowed_types' => $downloadService->settings()->allowedExtensions(),
        'max_size' => \CMS\Services\Downloads\DownloadSecurity::formatBytes($downloadService->settings()->maxSizeBytes()),
    ]);
}

if ($action === 'download_upload') {
    $downloadService = \CMS\Services\Downloads\DownloadService::getInstance();
    $uploadFile = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : null;
    if ($uploadFile === null) {
        $cmsPostEditorApiRespond(422, ['success' => false, 'error' => __('downloads.error.no_file')]);
    }
    $uploadProperties = is_array($_POST['properties'] ?? null) ? $_POST['properties'] : [];
    // Neue Eigenschaftsfelder gehören zu den Download-Einstellungen und brauchen deshalb manage_downloads.
    $newProperties = json_decode(is_string($_POST['new_properties'] ?? null) ? $_POST['new_properties'] : '[]', true, 4);
    $newProperties = is_array($newProperties) ? array_slice(array_values(array_filter($newProperties, 'is_array')), 0, 10) : [];
    $addedPropertyKeys = [];
    if ($newProperties !== []) {
        if (!$auth->hasCapability('manage_downloads')) {
            $cmsPostEditorApiRespond(403, ['success' => false, 'error' => __('downloads.block.property_forbidden')]);
        }
        $downloadSettings = $downloadService->settings();
        $propertyFields = $downloadSettings->propertyFields();
        $knownFieldCount = count($propertyFields);
        foreach ($newProperties as $newProperty) {
            $newLabel = is_scalar($newProperty['label'] ?? null) ? trim((string) $newProperty['label']) : '';
            if ($newLabel === '') {
                continue;
            }
            [$propertyFields, $newKey] = \CMS\Services\Downloads\DownloadSettings::appendPropertyField(
                $propertyFields,
                $newLabel,
                is_scalar($newProperty['type'] ?? null) ? (string) $newProperty['type'] : 'text'
            );
            if ($newKey === '') {
                $cmsPostEditorApiRespond(422, ['success' => false, 'error' => sprintf(__('downloads.block.property_create_failed'), $newLabel)]);
            }
            if (is_scalar($newProperty['value'] ?? null) && !array_key_exists($newKey, $uploadProperties)) {
                $uploadProperties[$newKey] = (string) $newProperty['value'];
            }
        }
        if (count($propertyFields) > $knownFieldCount) {
            if (!$downloadSettings->save(['property_fields' => $propertyFields])) {
                $cmsPostEditorApiRespond(500, ['success' => false, 'error' => __('downloads.admin.settings_failed')]);
            }
            $addedPropertyKeys = array_column(array_slice($propertyFields, $knownFieldCount), 'key');
        }
    }
    $uploadResult = $downloadService->createFromUpload($uploadFile, [
        'title' => is_scalar($_POST['title'] ?? null) ? (string) $_POST['title'] : '',
        'summary' => is_scalar($_POST['summary'] ?? null) ? (string) $_POST['summary'] : '',
        'category_id' => max(0, (int) ($_POST['category_id'] ?? 0)),
        'version' => is_scalar($_POST['version'] ?? null) ? (string) $_POST['version'] : '',
        'properties' => $uploadProperties,
    ], (int) ($auth->getCurrentUser()->id ?? 0));
    if (empty($uploadResult['success'])) {
        // Ohne Download bleiben die eben angelegten Eigenschaftsfelder nicht in den Einstellungen stehen.
        if ($addedPropertyKeys !== []) {
            $downloadService->settings()->save(['property_fields' => array_values(array_filter(
                $downloadService->settings()->propertyFields(),
                static fn (array $field): bool => !in_array($field['key'], $addedPropertyKeys, true)
            ))]);
        }
        $cmsPostEditorApiRespond(422, ['success' => false, 'error' => (string) ($uploadResult['error'] ?? __('downloads.error.upload_failed'))]);
    }
    if ($addedPropertyKeys !== []) {
        \CMS\AuditLogger::instance()->log(\CMS\AuditLogger::CAT_SETTING, 'downloads.settings.property_fields', 'Eigenschaftsfelder im Editor ergänzt', 'downloads', null, [
            'fields' => $addedPropertyKeys,
        ]);
    }
    $uploaded = $downloadService->get((int) $uploadResult['id']);
    $cmsPostEditorApiRespond(200, [
        'success' => true,
        'item' => [
            'id' => (int) $uploadResult['id'],
            'title' => (string) ($uploaded['title'] ?? ''),
            'status' => (string) ($uploaded['status'] ?? ''),
            'version' => (string) ($uploaded['current_version'] ?? ''),
            'edit_url' => '/admin/downloads?action=edit&id=' . (int) $uploadResult['id'],
            // Ausgefüllte Eigenschaften: der Block zeigt sie nach dem Upload an (Option showProperties).
            'property_keys' => array_keys($downloadService->repository()->propertyValuesForItems([(int) $uploadResult['id']])[(int) $uploadResult['id']] ?? []),
        ],
        'property_fields' => $downloadService->settings()->propertyFields(),
    ]);
}

if ($action === 'block_preview') {
    $blockType = trim((string) ($_POST['type'] ?? ''));
    if (!EditorJsBlockRegistry::has($blockType)) {
        $cmsPostEditorApiRespond(404, ['success' => false, 'error' => 'Unbekannter Blocktyp.']);
    }

    $rawData = (string) ($_POST['data'] ?? '');
    $rawTunes = (string) ($_POST['tunes'] ?? '');
    if (strlen($rawData) > 65536 || strlen($rawTunes) > 8192) {
        $cmsPostEditorApiRespond(413, ['success' => false, 'error' => 'Blockdaten sind zu groß.']);
    }

    $blockData = json_decode($rawData !== '' ? $rawData : '{}', true, 16);
    $blockTunes = json_decode($rawTunes !== '' ? $rawTunes : '{}', true, 8);
    if (!is_array($blockData) || !is_array($blockTunes)) {
        $cmsPostEditorApiRespond(422, ['success' => false, 'error' => 'Blockdaten sind kein gültiges JSON.']);
    }

    $html = EditorJsBlockRegistry::render(
        $blockType,
        EditorJsBlockRegistry::sanitize($blockType, $blockData),
        $blockTunes,
        ['mode' => 'preview']
    );
    $cmsPostEditorApiRespond(200, ['success' => true, 'html' => $html]);
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
