<?php
declare(strict_types=1);

use CMS\Member\MemberI18n;

if (!defined('ABSPATH')) {
    exit;
}

require __DIR__ . '/includes/bootstrap.php';

$controller->enforceProfileCompletion('media');
$controller->handleMediaRequest();

$pageTitle = $t('member.nav.media');
$pageKey = 'media';
$pageAssets = [];
$media = $controller->getMediaOverview();
$items = is_array($media['items'] ?? null) ? $media['items'] : ['folders' => [], 'files' => []];
$folders = is_array($items['folders'] ?? null) ? $items['folders'] : [];
$files = is_array($items['files'] ?? null) ? $items['files'] : [];
$mediaSettings = is_array($media['settings'] ?? null) ? $media['settings'] : [];
$memberPath = (string)($media['path'] ?? 'member');
$breadcrumbs = is_array($media['breadcrumbs'] ?? null) ? $media['breadcrumbs'] : [];
$moveTargets = is_array($media['move_targets'] ?? null) ? $media['move_targets'] : [];
// Ein Token für alle Formulare dieser Seite (MD-04); MemberController::csrfToken() liefert je Request dasselbe.
$mediaToken = $controller->csrfToken('media_action');
$canDelete = !empty($mediaSettings['member_delete_own']);

$memberMediaConfig = [
    'currentPath' => $memberPath,
    'renameModalId' => 'memberMediaRenameModal',
    'renamePathFieldId' => 'memberMediaRenamePath',
    'renameNameFieldId' => 'memberMediaRenameName',
    'renameLabelId' => 'memberMediaRenameLabel',
    'moveModalId' => 'memberMediaMoveModal',
    'movePathFieldId' => 'memberMediaMovePath',
    'moveTargetFieldId' => 'memberMediaMoveTarget',
    'moveLabelId' => 'memberMediaMoveLabel',
    'i18n' => [
        'selectFiles' => $t('member.media.upload_select'),
        'uploading' => $t('member.media.upload_running'),
        'progress' => $t('member.media.upload_progress'),
        'success' => $t('member.media.upload_file_ok'),
        'failed' => $t('member.media.upload_failed'),
        'doneWithErrors' => $t('member.media.upload_done_errors'),
        'done' => $t('member.media.upload_done'),
    ],
];

/** Aktionsmenü einer Zeile mit kontextbezogenen Bezeichnungen (UI-10). */
$renderActions = static function (string $itemPath, string $itemName, string $kind, string $deleteAction, string $confirmText) use ($e, $t, $memberPath, $mediaToken, $canDelete): void {
    $kindLabel = $t($kind === 'folder' ? 'member.media.kind_folder' : 'member.media.kind_file');
    ?>
    <div class="dropdown">
        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <?= $e($t('member.media.actions')) ?><span class="visually-hidden">: <?= $e($itemName) ?></span>
        </button>
        <div class="dropdown-menu dropdown-menu-end">
            <button type="button" class="dropdown-item js-member-media-open-rename" data-bs-toggle="modal" data-bs-target="#memberMediaRenameModal" data-media-path="<?= $e($itemPath) ?>" data-media-name="<?= $e($itemName) ?>" data-media-kind="<?= $e($kindLabel) ?>">
                <?= $e($t('member.media.rename')) ?><span class="visually-hidden">: <?= $e($itemName) ?></span>
            </button>
            <button type="button" class="dropdown-item js-member-media-open-move" data-bs-toggle="modal" data-bs-target="#memberMediaMoveModal" data-media-path="<?= $e($itemPath) ?>" data-media-name="<?= $e($itemName) ?>" data-media-kind="<?= $e($kindLabel) ?>" data-media-target="<?= $e($memberPath) ?>">
                <?= $e($t('member.media.move')) ?><span class="visually-hidden">: <?= $e($itemName) ?></span>
            </button>
            <?php if ($canDelete): ?>
                <form method="post" action="" data-cms-confirm="<?= $e($confirmText) ?>">
                    <input type="hidden" name="action" value="<?= $e($deleteAction) ?>">
                    <input type="hidden" name="path" value="<?= $e($itemPath) ?>">
                    <input type="hidden" name="current_path" value="<?= $e($memberPath) ?>">
                    <input type="hidden" name="csrf_token" value="<?= $e($mediaToken) ?>">
                    <button type="submit" class="dropdown-item text-danger"><?= $e($t('member.common.delete')) ?><span class="visually-hidden">: <?= $e($itemName) ?></span></button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php
};

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-12 col-xl-4 vstack gap-4">
        <section class="card" aria-labelledby="member-upload-title">
            <div class="card-header"><h2 class="card-title" id="member-upload-title"><?= $e($t('member.media.upload_title')) ?></h2></div>
            <div class="card-body">
                <?php if (!empty($mediaSettings['member_uploads_enabled'])): ?>
                    <form method="post" action="" class="vstack gap-3" data-member-upload-form data-upload-endpoint="/api/upload" data-upload-token="<?= $e(\CMS\Security::instance()->generateToken('media_action')) ?>" data-upload-path="<?= $e($memberPath) ?>">
                        <div>
                            <label class="form-label" for="member_upload_files"><?= $e($t('member.media.upload_label')) ?></label>
                            <input type="file" class="form-control" id="member_upload_files" name="member_upload_files[]" multiple required aria-describedby="member_upload_hint">
                            <div class="form-hint" id="member_upload_hint">
                                <?= $e($t('member.media.upload_hint', ['size' => (int)($mediaSettings['member_max_upload_size'] ?? 10), 'types' => implode(', ', array_map('strval', (array)($mediaSettings['member_allowed_types'] ?? [])))])) ?>
                            </div>
                        </div>
                        <div class="text-secondary small" role="status" aria-live="polite" data-member-upload-status hidden></div>
                        <div class="vstack gap-2" data-member-upload-results hidden></div>
                        <div>
                            <button type="submit" class="btn btn-primary"><?= $e($t('member.media.upload_start')) ?></button>
                        </div>
                    </form>
                <?php else: ?>
                    <p class="text-secondary mb-0"><?= $e($t('member.media.upload_disabled')) ?></p>
                <?php endif; ?>
            </div>
        </section>
        <form class="card" method="post" action="" aria-labelledby="member-folder-create-title">
            <input type="hidden" name="action" value="media_folder_create">
            <input type="hidden" name="csrf_token" value="<?= $e($mediaToken) ?>">
            <input type="hidden" name="current_path" value="<?= $e($memberPath) ?>">
            <div class="card-header"><h2 class="card-title" id="member-folder-create-title"><?= $e($t('member.media.folder_create')) ?></h2></div>
            <div class="card-body">
                <label class="form-label" for="folder_name"><?= $e($t('member.media.folder_name')) ?></label>
                <input class="form-control" id="folder_name" name="folder_name" type="text" maxlength="120" required>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary"><?= $e($t('member.media.folder_create_submit')) ?></button>
            </div>
        </form>
    </div>
    <div class="col-12 col-xl-8 vstack gap-4">
        <section class="card" aria-labelledby="member-folders-title">
            <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <h2 class="card-title mb-0" id="member-folders-title"><?= $e($t('member.media.folders')) ?></h2>
                <nav aria-label="<?= $e($t('member.a11y.breadcrumb')) ?>">
                    <ol class="breadcrumb mb-0">
                        <?php foreach ($breadcrumbs as $index => $breadcrumb): ?>
                            <?php $isLast = !empty($breadcrumb['active']) || $index === count($breadcrumbs) - 1; ?>
                            <?php if ($isLast): ?>
                                <li class="breadcrumb-item active" aria-current="page"><?= $e($breadcrumb['label'] ?? '') ?></li>
                            <?php else: ?>
                                <li class="breadcrumb-item"><a href="<?= $e($breadcrumb['url'] ?? '/member/media') ?>"><?= $e($breadcrumb['label'] ?? '') ?></a></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            </div>
            <?php if ($folders === []): ?>
                <div class="card-body text-secondary"><?= $e($t('member.media.folders_empty')) ?></div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($folders as $folder): ?>
                        <?php $folderPath = (string)($folder['path'] ?? ''); ?>
                        <?php $folderName = (string)($folder['name'] ?? $t('member.media.kind_folder')); ?>
                        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <div class="fw-medium">
                                    <a href="<?= $e('/member/media?path=' . rawurlencode($folderPath)) ?>" class="text-reset"><span aria-hidden="true">📁 </span><?= $e($folderName) ?></a>
                                </div>
                                <div class="text-secondary small text-break"><?= $e($folderPath) ?></div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <span class="text-secondary small"><?= $e($t('member.media.items_count', ['count' => (int)($folder['items_count'] ?? 0)])) ?></span>
                                <?php $renderActions($folderPath, $folderName, 'folder', 'media_folder_delete', $t('member.media.confirm_folder_delete')); ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="card" aria-labelledby="member-files-title">
            <div class="card-header"><h2 class="card-title" id="member-files-title"><?= $e($t('member.media.files')) ?></h2></div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <caption class="visually-hidden"><?= $e($t('member.media.files_caption', ['path' => $memberPath])) ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= $e($t('member.media.col_file')) ?></th>
                            <th scope="col"><?= $e($t('member.media.col_type')) ?></th>
                            <th scope="col"><?= $e($t('member.media.col_size')) ?></th>
                            <th scope="col"><?= $e($t('member.media.col_modified')) ?></th>
                            <th scope="col" class="w-1"><span class="visually-hidden"><?= $e($t('member.media.actions')) ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($files === []): ?>
                            <tr><td colspan="5" class="text-secondary"><?= $e($t('member.media.files_empty')) ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($files as $file): ?>
                                <?php $filePath = (string)($file['path'] ?? ''); ?>
                                <?php $fileName = (string)($file['name'] ?? $t('member.media.kind_file')); ?>
                                <tr>
                                    <td>
                                        <div class="fw-medium"><a href="<?= $e(\CMS\Member\MemberSanitizer::link($file['url'] ?? '#', '#')) ?>" target="_blank" rel="noopener noreferrer"><?= $e($fileName) ?><span class="visually-hidden"> <?= $e($t('member.a11y.new_window')) ?></span></a></div>
                                        <div class="text-secondary small text-break"><?= $e($filePath) ?></div>
                                    </td>
                                    <td><?= $e(strtoupper((string)($file['type'] ?? ''))) ?></td>
                                    <td><?= $e(number_format(((int)($file['size'] ?? 0)) / 1024, 1, MemberI18n::locale() === 'en' ? '.' : ',', MemberI18n::locale() === 'en' ? ',' : '.')) ?> KB</td>
                                    <td><?= !empty($file['modified']) ? $e(MemberI18n::formatDate((string)(int)$file['modified'])) : '–' ?></td>
                                    <td><?php $renderActions($filePath, $fileName, 'file', 'media_delete', $t('member.media.confirm_file_delete')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<div class="modal modal-blur fade" id="memberMediaRenameModal" tabindex="-1" aria-hidden="true" aria-labelledby="memberMediaRenameTitle">
    <div class="modal-dialog modal-sm">
        <form method="post" action="" class="modal-content">
            <input type="hidden" name="action" value="media_rename">
            <input type="hidden" name="path" id="memberMediaRenamePath">
            <input type="hidden" name="current_path" value="<?= $e($memberPath) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($mediaToken) ?>">
            <div class="modal-header">
                <h2 class="modal-title h5" id="memberMediaRenameTitle"><?= $e($t('member.media.rename_title')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($t('member.a11y.close_dialog')) ?>"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small mb-2"><?= $e($t('member.media.rename_for')) ?> <span class="fw-semibold" id="memberMediaRenameLabel"></span></p>
                <label class="form-label" for="memberMediaRenameName"><?= $e($t('member.media.name')) ?></label>
                <input class="form-control" id="memberMediaRenameName" name="new_name" type="text" maxlength="120" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal"><?= $e($t('member.common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= $e($t('member.media.rename')) ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal modal-blur fade" id="memberMediaMoveModal" tabindex="-1" aria-hidden="true" aria-labelledby="memberMediaMoveTitle">
    <div class="modal-dialog modal-sm">
        <form method="post" action="" class="modal-content">
            <input type="hidden" name="action" value="media_move">
            <input type="hidden" name="path" id="memberMediaMovePath">
            <input type="hidden" name="current_path" value="<?= $e($memberPath) ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($mediaToken) ?>">
            <div class="modal-header">
                <h2 class="modal-title h5" id="memberMediaMoveTitle"><?= $e($t('member.media.move_title')) ?></h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= $e($t('member.a11y.close_dialog')) ?>"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small mb-2"><?= $e($t('member.media.move_for')) ?> <span class="fw-semibold" id="memberMediaMoveLabel"></span></p>
                <label class="form-label" for="memberMediaMoveTarget"><?= $e($t('member.media.target_folder')) ?></label>
                <select class="form-select" id="memberMediaMoveTarget" name="target_parent_path">
                    <?php foreach ($moveTargets as $target): ?>
                        <option value="<?= $e($target['path'] ?? '') ?>"<?= ((string)($target['path'] ?? '') === $memberPath) ? ' selected' : '' ?>><?= $e($target['label'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal"><?= $e($t('member.common.cancel')) ?></button>
                <button type="submit" class="btn btn-primary"><?= $e($t('member.media.move')) ?></button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="member-media-config"><?= json_encode($memberMediaConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php include __DIR__ . '/partials/footer.php';
