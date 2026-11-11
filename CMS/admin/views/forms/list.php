<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulare – Liste mit Statusfilter, Suche, Anlegen und Papierkorb.
 *
 * @var array<string, mixed> $fmData
 * @var string $csrfToken
 * @var callable $fmE
 * @var callable $fmT
 * @var callable $fmDate
 * @var callable $fmDays
 */

$fmForms = is_array($fmData['forms'] ?? null) ? $fmData['forms'] : [];
$fmFilter = (string) ($fmData['filter'] ?? 'all');
$fmSearch = (string) ($fmData['search'] ?? '');
$fmCounts = is_array($fmData['counts'] ?? null) ? $fmData['counts'] : [];
$fmContactKey = (string) ($fmData['contact_form_key'] ?? 'kontakt');
$fmFilters = [
    'all' => 'forms.admin.filter_all',
    'active' => 'forms.admin.filter_active',
    'inactive' => 'forms.admin.filter_inactive',
    'trash' => 'forms.admin.filter_trash',
];
$fmStatusBadge = ['active' => 'bg-green-lt', 'inactive' => 'bg-secondary-lt', 'trash' => 'bg-red-lt'];
$fmRowForm = static function (string $action, int $id, string $label, string $icon, array $confirm = []) use ($fmE, $csrfToken, $fmFilter): string {
    $attributes = '';
    foreach ($confirm as $name => $value) {
        $attributes .= ' data-confirm-' . $name . '="' . $fmE($value) . '"';
    }

    return '<form method="post" action="/admin/forms" class="d-inline"' . $attributes . '>'
        . '<input type="hidden" name="csrf_token" value="' . $fmE($csrfToken) . '">'
        . '<input type="hidden" name="action" value="' . $fmE($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="return_status" value="' . $fmE($fmFilter) . '">'
        . '<button type="submit" class="dropdown-item"><i class="ti ti-' . $fmE($icon) . ' me-2" aria-hidden="true"></i>' . $fmE($label) . '</button></form>';
};
?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header flex-wrap gap-2">
                <ul class="nav nav-pills card-header-pills">
                    <?php foreach ($fmFilters as $fmFilterKey => $fmFilterLabel): ?>
                        <?php if ($fmFilterKey === 'trash' && (int) ($fmCounts['trash'] ?? 0) === 0 && $fmFilter !== 'trash') { continue; } ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $fmFilter === $fmFilterKey ? ' active' : '' ?>"<?= $fmFilter === $fmFilterKey ? ' aria-current="true"' : '' ?>
                               href="<?= $fmE(FormsModule::url(['status' => $fmFilterKey !== 'all' ? $fmFilterKey : null, 'q' => $fmSearch])) ?>">
                                <?= $fmT($fmFilterLabel) ?> <span class="badge bg-secondary-lt ms-1"><?= (int) ($fmCounts[$fmFilterKey] ?? 0) ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <form method="get" action="/admin/forms" class="ms-auto d-flex gap-2" role="search">
                    <?php if ($fmFilter !== 'all'): ?><input type="hidden" name="status" value="<?= $fmE($fmFilter) ?>"><?php endif; ?>
                    <input type="search" class="form-control form-control-sm" name="q" value="<?= $fmE($fmSearch) ?>" maxlength="100"
                           placeholder="<?= $fmT('forms.admin.search_placeholder') ?>" aria-label="<?= $fmT('forms.admin.search_placeholder') ?>">
                    <button type="submit" class="btn btn-sm"><?= $fmT('forms.admin.search') ?></button>
                </form>
            </div>

            <?php if ($fmForms === []): ?>
                <div class="card-body text-secondary"><?= $fmT($fmSearch !== '' ? 'forms.admin.empty_search' : ($fmFilter === 'trash' ? 'forms.admin.empty_trash' : 'forms.admin.empty')) ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th><?= $fmT('forms.admin.col_form') ?></th>
                                <th><?= $fmT('forms.admin.col_status') ?></th>
                                <th class="text-end"><?= $fmT('forms.admin.col_fields') ?></th>
                                <th class="text-end"><?= $fmT('forms.admin.col_submissions') ?></th>
                                <th><?= $fmT('forms.admin.col_retention') ?></th>
                                <th class="w-1"><span class="visually-hidden"><?= $fmT('forms.admin.col_actions') ?></span></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fmForms as $fmRow): ?>
                            <?php
                            $fmId = (int) $fmRow['id'];
                            $fmStatus = (string) $fmRow['status'];
                            $fmIsTrash = $fmStatus === 'trash';
                            $fmEditUrl = FormsModule::url(['action' => 'edit', 'id' => $fmId]);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($fmIsTrash): ?>
                                        <span class="fw-medium"><?= $fmE($fmRow['title']) ?></span>
                                    <?php else: ?>
                                        <a class="fw-medium" href="<?= $fmE($fmEditUrl) ?>"><?= $fmE($fmRow['title']) ?></a>
                                    <?php endif; ?>
                                    <?php if (!empty($fmRow['is_builtin'])): ?>
                                        <span class="badge bg-blue-lt ms-1"><?= $fmT('forms.admin.badge_builtin') ?></span>
                                    <?php endif; ?>
                                    <?php if ($fmRow['form_key'] === $fmContactKey && !$fmIsTrash): ?>
                                        <span class="badge bg-azure-lt ms-1"><?= $fmT('forms.admin.badge_contact_page') ?></span>
                                    <?php endif; ?>
                                    <div class="small text-secondary">
                                        <code><?= $fmE($fmRow['form_key']) ?></code>
                                        <?php if ($fmIsTrash): ?>
                                            · <?= $fmT('forms.admin.trashed_at', $fmDate((string) $fmRow['trashed_at'])) ?>
                                        <?php else: ?>
                                            · <?= $fmT('forms.admin.updated_at', $fmDate((string) $fmRow['updated_at'])) ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="badge <?= $fmE($fmStatusBadge[$fmStatus] ?? 'bg-secondary-lt') ?>"><?= $fmT('forms.admin.status_' . $fmStatus) ?></span></td>
                                <td class="text-end"><?= (int) $fmRow['field_count'] ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if (empty($fmRow['store_submissions'])): ?>
                                        <span class="text-secondary" title="<?= $fmT('forms.admin.not_stored_hint') ?>"><?= $fmT('forms.admin.not_stored') ?></span>
                                    <?php elseif ((string) ($fmData['submissions_url'] ?? '') !== ''): ?>
                                        <a href="<?= $fmE('/admin/form-submissions?form=' . $fmId) ?>"><?= (int) $fmRow['submissions'] ?></a>
                                    <?php else: ?>
                                        <?= (int) $fmRow['submissions'] ?>
                                    <?php endif; ?>
                                    <?php if ((int) $fmRow['submissions_new'] > 0): ?>
                                        <span class="badge bg-red-lt ms-1"><?= $fmT('forms.admin.new_count', (int) $fmRow['submissions_new']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <?= $fmE($fmDays((int) $fmRow['retention_days'])) ?>
                                    <?php if (!empty($fmRow['retention_inherited'])): ?>
                                        <span class="text-secondary small">(<?= $fmT('forms.admin.retention_inherited') ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="dropdown">
                                        <button type="button" class="btn btn-sm btn-icon" data-bs-toggle="dropdown" aria-expanded="false"
                                                aria-label="<?= $fmT('forms.admin.actions_for', (string) $fmRow['title']) ?>">
                                            <i class="ti ti-dots-vertical" aria-hidden="true"></i>
                                        </button>
                                        <div class="dropdown-menu dropdown-menu-end">
                                            <?php if ($fmIsTrash): ?>
                                                <?= $fmRowForm('restore', $fmId, (string) __('forms.admin.action_restore'), 'restore') ?>
                                                <?= $fmRowForm('delete', $fmId, (string) __('forms.admin.action_delete'), 'trash-x', [
                                                    'title' => (string) __('forms.admin.delete_confirm_title'),
                                                    'message' => sprintf((string) __('forms.admin.delete_confirm'), (string) $fmRow['title'], (int) $fmRow['submissions']),
                                                    'text' => (string) __('forms.admin.action_delete'),
                                                    'class' => 'btn-danger',
                                                ]) ?>
                                            <?php else: ?>
                                                <a class="dropdown-item" href="<?= $fmE($fmEditUrl) ?>"><i class="ti ti-edit me-2" aria-hidden="true"></i><?= $fmT('forms.admin.action_edit') ?></a>
                                                <?php if ($fmStatus === 'active'): ?>
                                                    <a class="dropdown-item" href="<?= $fmE((string) $fmRow['public_url']) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link me-2" aria-hidden="true"></i><?= $fmT('forms.admin.action_view') ?></a>
                                                <?php endif; ?>
                                                <?= $fmRowForm('duplicate', $fmId, (string) __('forms.admin.action_duplicate'), 'copy') ?>
                                                <?= $fmStatus === 'active'
                                                    ? $fmRowForm('deactivate', $fmId, (string) __('forms.admin.action_deactivate'), 'player-pause')
                                                    : $fmRowForm('activate', $fmId, (string) __('forms.admin.action_activate'), 'player-play') ?>
                                                <?php if (empty($fmRow['is_builtin'])): ?>
                                                    <?= $fmRowForm('trash', $fmId, (string) __('forms.admin.action_trash'), 'trash', [
                                                        'title' => (string) __('forms.admin.trash_confirm_title'),
                                                        'message' => sprintf((string) __('forms.admin.trash_confirm'), (string) $fmRow['title']),
                                                        'text' => (string) __('forms.admin.action_trash'),
                                                        'class' => 'btn-danger',
                                                    ]) ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <form method="post" action="/admin/forms" class="card">
            <input type="hidden" name="csrf_token" value="<?= $fmE($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <div class="card-header"><h3 class="card-title"><?= $fmT('forms.admin.create_title') ?></h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label required" for="fmCreateTitle"><?= $fmT('forms.admin.field_title') ?></label>
                    <input type="text" class="form-control" id="fmCreateTitle" name="title" maxlength="255" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="fmCreateKey"><?= $fmT('forms.admin.field_key') ?></label>
                    <input type="text" class="form-control" id="fmCreateKey" name="form_key" maxlength="64" pattern="[a-z0-9][a-z0-9\-]{0,63}" aria-describedby="fmCreateKeyHint">
                    <div class="form-hint" id="fmCreateKeyHint"><?= $fmT('forms.admin.field_key_hint') ?></div>
                </div>
                <fieldset>
                    <legend class="form-label"><?= $fmT('forms.admin.field_template') ?></legend>
                    <label class="form-check">
                        <input class="form-check-input" type="radio" name="template" value="blank" checked>
                        <span class="form-check-label"><?= $fmT('forms.admin.template_blank') ?></span>
                    </label>
                    <label class="form-check mb-0">
                        <input class="form-check-input" type="radio" name="template" value="contact">
                        <span class="form-check-label"><?= $fmT('forms.admin.template_contact') ?></span>
                    </label>
                </fieldset>
            </div>
            <div class="card-footer text-end">
                <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $fmT('forms.admin.create_submit') ?></button>
            </div>
        </form>

        <div class="card mt-4">
            <div class="card-body">
                <h3 class="card-title"><?= $fmT('forms.admin.embed_title') ?></h3>
                <p class="text-secondary mb-0"><?= $fmT('forms.admin.embed_text') ?></p>
            </div>
        </div>
    </div>
</div>
