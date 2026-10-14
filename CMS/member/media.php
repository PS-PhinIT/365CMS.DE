<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/bootstrap.php';

$controller->handleMediaRequest();

$pageTitle = 'Dateien';
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
];

include __DIR__ . '/partials/header.php';
?>
<div class="row g-4">
    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Dateien hochladen</h3></div>
            <div class="card-body">
                <?php if (!empty($mediaSettings['member_uploads_enabled'])): ?>
                    <form method="post" action="" class="vstack gap-3" data-member-upload-form data-upload-endpoint="<?= htmlspecialchars('/api/upload', ENT_QUOTES) ?>" data-upload-token="<?= htmlspecialchars(\CMS\Security::instance()->generateToken('media_action'), ENT_QUOTES) ?>" data-upload-path="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">
                        <input type="file" class="form-control" name="member_upload_files[]" aria-label="Dateien zum Hochladen auswählen" multiple required>
                        <div class="text-secondary small" role="status" aria-live="polite" data-member-upload-status hidden></div>
                        <div class="vstack gap-2" aria-live="polite" data-member-upload-results hidden></div>
                        <div>
                            <button type="submit" class="btn btn-primary">Upload starten</button>
                        </div>
                    </form>
                    <div class="text-secondary small mt-3">
                        Max. Größe: <?= (int)($mediaSettings['member_max_upload_size'] ?? 10) ?> MB · Erlaubte Typen: <?= htmlspecialchars(implode(', ', (array)($mediaSettings['member_allowed_types'] ?? []))) ?>
                    </div>
                <?php else: ?>
                    <div class="text-secondary">Uploads für Mitglieder sind aktuell deaktiviert.</div>
                <?php endif; ?>
            </div>
        </div>
        <form class="card" method="post" action="">
            <input type="hidden" name="action" value="media_folder_create">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('media_action'), ENT_QUOTES) ?>">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">
            <div class="card-header"><h3 class="card-title">Ordner erstellen</h3></div>
            <div class="card-body">
                <label class="form-label" for="folder_name">Ordnername</label>
                <input class="form-control" id="folder_name" name="folder_name" type="text" required>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary">Ordner anlegen</button>
            </div>
        </form>
    </div>
    <div class="col-xl-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center gap-3 flex-wrap">
                <h3 class="card-title mb-0">Ordner</h3>
                <nav aria-label="Breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <?php foreach ($breadcrumbs as $index => $breadcrumb): ?>
                            <?php $isLast = !empty($breadcrumb['active']) || $index === count($breadcrumbs) - 1; ?>
                            <?php if ($isLast): ?>
                                <li class="breadcrumb-item active"><?= htmlspecialchars((string)($breadcrumb['label'] ?? '')) ?></li>
                            <?php else: ?>
                                <li class="breadcrumb-item"><a href="<?= htmlspecialchars((string)($breadcrumb['url'] ?? '/member/media'), ENT_QUOTES) ?>"><?= htmlspecialchars((string)($breadcrumb['label'] ?? '')) ?></a></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </nav>
            </div>
            <div class="list-group list-group-flush">
                <?php if ($folders === []): ?>
                    <div class="card-body text-secondary">Noch keine Ordner angelegt.</div>
                <?php else: ?>
                    <?php foreach ($folders as $folder): ?>
                        <?php $folderPath = (string)($folder['path'] ?? ''); ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-medium">
                                    <a href="<?= htmlspecialchars('/member/media?path=' . rawurlencode($folderPath), ENT_QUOTES) ?>" class="text-reset text-decoration-none">📁 <?= htmlspecialchars((string)($folder['name'] ?? 'Ordner')) ?></a>
                                </div>
                                <div class="text-secondary small"><?= htmlspecialchars($folderPath) ?></div>
                            </div>
                            <div class="d-flex align-items-start gap-3">
                                <div class="text-secondary small"><?= (int)($folder['items_count'] ?? 0) ?> Elemente</div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Aktionen</button>
                                    <div class="dropdown-menu dropdown-menu-end">
                                        <button type="button" class="dropdown-item js-member-media-open-rename" data-bs-toggle="modal" data-bs-target="#memberMediaRenameModal" data-media-path="<?= htmlspecialchars($folderPath, ENT_QUOTES) ?>" data-media-name="<?= htmlspecialchars((string)($folder['name'] ?? 'Ordner'), ENT_QUOTES) ?>" data-media-kind="Ordner">Umbenennen</button>
                                        <button type="button" class="dropdown-item js-member-media-open-move" data-bs-toggle="modal" data-bs-target="#memberMediaMoveModal" data-media-path="<?= htmlspecialchars($folderPath, ENT_QUOTES) ?>" data-media-name="<?= htmlspecialchars((string)($folder['name'] ?? 'Ordner'), ENT_QUOTES) ?>" data-media-kind="Ordner" data-media-target="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">Verschieben</button>
                                        <?php if (!empty($mediaSettings['member_delete_own'])): ?>
                                            <form method="post" action="" data-cms-confirm="Ordner wirklich löschen? Alle enthaltenen Dateien werden ebenfalls entfernt.">
                                                <input type="hidden" name="action" value="media_folder_delete">
                                                <input type="hidden" name="path" value="<?= htmlspecialchars($folderPath, ENT_QUOTES) ?>">
                                                <input type="hidden" name="current_path" value="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('media_action'), ENT_QUOTES) ?>">
                                                <button type="submit" class="dropdown-item text-danger">Löschen</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Dateien</h3></div>
            <div class="table-responsive">
                <table class="table card-table table-vcenter">
                    <thead>
                        <tr>
                            <th>Datei</th>
                            <th>Typ</th>
                            <th>Größe</th>
                            <th>Geändert</th>
                            <th class="w-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($files === []): ?>
                            <tr><td colspan="5" class="text-secondary">Noch keine Dateien vorhanden.</td></tr>
                        <?php else: ?>
                            <?php foreach ($files as $file): ?>
                                <?php $filePath = (string)($file['path'] ?? ''); ?>
                                <tr>
                                    <td>
                                        <div class="fw-medium"><a href="<?= htmlspecialchars((string)($file['url'] ?? '#')) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars((string)($file['name'] ?? 'Datei')) ?></a></div>
                                        <div class="text-secondary small"><?= htmlspecialchars($filePath) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars(strtoupper((string)($file['type'] ?? ''))) ?></td>
                                    <td><?= number_format(((int)($file['size'] ?? 0)) / 1024, 1, ',', '.') ?> KB</td>
                                    <td><?= !empty($file['modified']) ? htmlspecialchars(date('d.m.Y H:i', (int)$file['modified'])) : '–' ?></td>
                                    <td>
                                        <div class="dropdown">
                                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">Aktionen</button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <button type="button" class="dropdown-item js-member-media-open-rename" data-bs-toggle="modal" data-bs-target="#memberMediaRenameModal" data-media-path="<?= htmlspecialchars($filePath, ENT_QUOTES) ?>" data-media-name="<?= htmlspecialchars((string)($file['name'] ?? 'Datei'), ENT_QUOTES) ?>" data-media-kind="Datei">Umbenennen</button>
                                                <button type="button" class="dropdown-item js-member-media-open-move" data-bs-toggle="modal" data-bs-target="#memberMediaMoveModal" data-media-path="<?= htmlspecialchars($filePath, ENT_QUOTES) ?>" data-media-name="<?= htmlspecialchars((string)($file['name'] ?? 'Datei'), ENT_QUOTES) ?>" data-media-kind="Datei" data-media-target="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">Verschieben</button>
                                                <?php if (!empty($mediaSettings['member_delete_own'])): ?>
                                                    <form method="post" action="" data-cms-confirm="Datei wirklich löschen?">
                                                        <input type="hidden" name="action" value="media_delete">
                                                        <input type="hidden" name="path" value="<?= htmlspecialchars($filePath, ENT_QUOTES) ?>">
                                                        <input type="hidden" name="current_path" value="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('media_action'), ENT_QUOTES) ?>">
                                                        <button type="submit" class="dropdown-item text-danger">Löschen</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<div class="modal modal-blur fade" id="memberMediaRenameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form method="post" action="" class="modal-content">
            <input type="hidden" name="action" value="media_rename">
            <input type="hidden" name="path" id="memberMediaRenamePath">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('media_action'), ENT_QUOTES) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Element umbenennen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="text-secondary small mb-2">Neuer Name für <span class="fw-semibold" id="memberMediaRenameLabel">Element</span></div>
                <label class="form-label" for="memberMediaRenameName">Name</label>
                <input class="form-control" id="memberMediaRenameName" name="new_name" type="text" maxlength="120" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Umbenennen</button>
            </div>
        </form>
    </div>
</div>

<div class="modal modal-blur fade" id="memberMediaMoveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form method="post" action="" class="modal-content">
            <input type="hidden" name="action" value="media_move">
            <input type="hidden" name="path" id="memberMediaMovePath">
            <input type="hidden" name="current_path" value="<?= htmlspecialchars($memberPath, ENT_QUOTES) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($controller->csrfToken('media_action'), ENT_QUOTES) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Element verschieben</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
            </div>
            <div class="modal-body">
                <div class="text-secondary small mb-2">Zielordner für <span class="fw-semibold" id="memberMediaMoveLabel">Element</span></div>
                <label class="form-label" for="memberMediaMoveTarget">Zielordner</label>
                <select class="form-select" id="memberMediaMoveTarget" name="target_parent_path">
                    <?php foreach ($moveTargets as $target): ?>
                        <option value="<?= htmlspecialchars((string)($target['path'] ?? ''), ENT_QUOTES) ?>" <?= ((string)($target['path'] ?? '') === $memberPath) ? 'selected' : '' ?>><?= htmlspecialchars((string)($target['label'] ?? 'Meine Dateien')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-bs-dismiss="modal">Abbrechen</button>
                <button type="submit" class="btn btn-primary">Verschieben</button>
            </div>
        </form>
    </div>
</div>

<script type="application/json" id="member-media-config"><?= json_encode($memberMediaConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php include __DIR__ . '/partials/footer.php';
