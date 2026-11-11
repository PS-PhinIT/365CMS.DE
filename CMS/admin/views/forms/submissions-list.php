<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Formulareingänge – Übersicht mit Filtern, Sammelaktionen, CSV-Export und Seitenwechsel.
 *
 * Die Sammelaktion ist ein eigenes Formular (`fsBulkForm`); die Auswahl-Kästchen gehören über das
 * `form`-Attribut dazu, damit keine Formulare verschachtelt werden.
 *
 * @var array<string, mixed> $fsData
 * @var string $csrfToken
 * @var bool $fsCanManage
 * @var callable $fsE
 * @var callable $fsT
 * @var callable $fsDate
 * @var array<string, string> $fsStatusBadge
 */

$fsFilters = is_array($fsData['filters'] ?? null) ? $fsData['filters'] : ['form' => 0, 'status' => 'all', 'q' => '', 'page' => 1];
$fsItems = is_array($fsData['items'] ?? null) ? $fsData['items'] : [];
$fsCounts = is_array($fsData['counts'] ?? null) ? $fsData['counts'] : [];
$fsForms = is_array($fsData['forms'] ?? null) ? $fsData['forms'] : [];
$fsTotal = (int) ($fsData['total'] ?? 0);
$fsPages = (int) ($fsData['pages'] ?? 1);
$fsPage = (int) $fsFilters['page'];
$fsIsTrash = $fsFilters['status'] === 'trash';
$fsUrl = static fn (array $overrides = []): string => FormSubmissionsModule::url(array_merge(
    ['form' => $fsFilters['form'], 'status' => $fsFilters['status'], 'q' => $fsFilters['q']],
    $overrides
));
$fsStatusTabs = [
    'all' => 'forms.inbox.filter_all',
    'new' => 'forms.status.new',
    'read' => 'forms.status.read',
    'done' => 'forms.status.done',
    'trash' => 'forms.status.trash',
];
$fsHiddenFilter = '';
foreach (['form', 'status', 'q', 'page'] as $fsKey) {
    $fsHiddenFilter .= '<input type="hidden" name="filter[' . $fsKey . ']" value="' . $fsE((string) $fsFilters[$fsKey]) . '">';
}
$fsBulkOptions = $fsIsTrash
    ? ['restore' => 'forms.admin.action_restore'] + ($fsCanManage ? ['delete' => 'forms.admin.action_delete'] : [])
    : ['mark_read' => 'forms.inbox.bulk_read', 'mark_done' => 'forms.inbox.bulk_done_action', 'mark_new' => 'forms.inbox.bulk_new', 'trash' => 'forms.admin.action_trash'];
?>

<div class="card">
    <div class="card-header flex-wrap gap-2">
        <ul class="nav nav-pills card-header-pills">
            <?php foreach ($fsStatusTabs as $fsStatusKey => $fsStatusLabel): ?>
                <?php if ($fsStatusKey === 'trash' && (int) ($fsCounts['trash'] ?? 0) === 0 && !$fsIsTrash) { continue; } ?>
                <li class="nav-item">
                    <a class="nav-link<?= $fsFilters['status'] === $fsStatusKey ? ' active' : '' ?>"<?= $fsFilters['status'] === $fsStatusKey ? ' aria-current="true"' : '' ?>
                       href="<?= $fsE($fsUrl(['status' => $fsStatusKey])) ?>">
                        <?= $fsT($fsStatusLabel) ?> <span class="badge <?= $fsStatusKey === 'new' && (int) ($fsCounts['new'] ?? 0) > 0 ? 'bg-blue-lt' : 'bg-secondary-lt' ?> ms-1"><?= (int) ($fsCounts[$fsStatusKey] ?? 0) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="get" action="/admin/form-submissions" class="ms-auto d-flex flex-wrap gap-2" role="search">
            <?php if ($fsFilters['status'] !== 'all'): ?><input type="hidden" name="status" value="<?= $fsE($fsFilters['status']) ?>"><?php endif; ?>
            <select class="form-select form-select-sm w-auto" name="form" aria-label="<?= $fsT('forms.inbox.filter_form') ?>">
                <option value="0"><?= $fsT('forms.inbox.all_forms') ?></option>
                <?php foreach ($fsForms as $fsFormId => $fsFormTitle): ?>
                    <option value="<?= (int) $fsFormId ?>"<?= (int) $fsFilters['form'] === (int) $fsFormId ? ' selected' : '' ?>><?= $fsE($fsFormTitle) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="search" class="form-control form-control-sm w-auto" name="q" value="<?= $fsE($fsFilters['q']) ?>" maxlength="100"
                   placeholder="<?= $fsT('forms.inbox.search_placeholder') ?>" aria-label="<?= $fsT('forms.inbox.search_placeholder') ?>">
            <button type="submit" class="btn btn-sm"><?= $fsT('forms.admin.search') ?></button>
            <?php if ($fsTotal > 0): ?>
                <a class="btn btn-sm" href="<?= $fsE($fsUrl(['export' => 'csv'])) ?>"><i class="ti ti-download me-1" aria-hidden="true"></i><?= $fsT('forms.inbox.export_csv') ?></a>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($fsItems === []): ?>
        <div class="card-body text-secondary">
            <?= $fsT($fsFilters['q'] !== '' ? 'forms.inbox.empty_search' : ($fsIsTrash ? 'forms.admin.empty_trash' : 'forms.inbox.empty')) ?>
        </div>
    <?php else: ?>
        <form method="post" action="/admin/form-submissions" id="fsBulkForm" class="card-body border-bottom py-2 d-flex flex-wrap align-items-center gap-2" data-fs-bulk
              data-fs-delete-title="<?= $fsT('forms.inbox.bulk_delete_title') ?>" data-fs-delete-message="<?= $fsT('forms.inbox.bulk_delete_confirm') ?>"
              data-fs-delete-text="<?= $fsT('forms.admin.action_delete') ?>" data-fs-selected-text="<?= $fsT('forms.inbox.selected') ?>">
            <input type="hidden" name="csrf_token" value="<?= $fsE($csrfToken) ?>">
            <input type="hidden" name="action" value="bulk">
            <?= $fsHiddenFilter ?>
            <label class="visually-hidden" for="fsBulkAction"><?= $fsT('forms.inbox.bulk_label') ?></label>
            <select class="form-select form-select-sm w-auto" id="fsBulkAction" name="bulk_action" required>
                <option value=""><?= $fsT('forms.inbox.bulk_label') ?></option>
                <?php foreach ($fsBulkOptions as $fsBulkKey => $fsBulkLabel): ?>
                    <option value="<?= $fsE($fsBulkKey) ?>"><?= $fsT($fsBulkLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm" data-fs-bulk-submit><?= $fsT('forms.inbox.bulk_apply') ?></button>
            <span class="text-secondary small" data-fs-selected aria-live="polite"></span>
            <span class="text-secondary small ms-auto"><?= $fsT('forms.inbox.total', $fsTotal) ?></span>
        </form>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1"><input type="checkbox" class="form-check-input" data-fs-check-all aria-label="<?= $fsT('forms.inbox.select_all') ?>"></th>
                        <th><?= $fsT('forms.inbox.col_sender') ?></th>
                        <th><?= $fsT('forms.inbox.col_subject') ?></th>
                        <th><?= $fsT('forms.admin.col_form') ?></th>
                        <th><?= $fsT('forms.inbox.col_received') ?></th>
                        <th><?= $fsT('forms.admin.col_status') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($fsItems as $fsItem): ?>
                    <?php
                    $fsId = (int) $fsItem['id'];
                    $fsStatus = (string) $fsItem['status'];
                    $fsSender = (string) ($fsItem['sender_name'] !== '' ? $fsItem['sender_name'] : ($fsItem['sender_email'] !== '' ? $fsItem['sender_email'] : __('forms.inbox.anonymous')));
                    $fsSubject = (string) $fsItem['subject'];
                    if ($fsSubject === '') {
                        foreach ($fsItem['data'] as $fsEntry) {
                            if (in_array($fsEntry['type'] ?? '', ['textarea', 'text'], true) && trim((string) ($fsEntry['text'] ?? '')) !== '' && ($fsEntry['text'] ?? '') !== $fsItem['sender_name']) {
                                $fsSubject = (string) $fsEntry['text'];
                                break;
                            }
                        }
                    }
                    $fsSubject = function_exists('mb_strimwidth') ? mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $fsSubject)), 0, 90, '…', 'UTF-8') : substr($fsSubject, 0, 90);
                    ?>
                    <tr class="<?= $fsStatus === 'new' ? 'cms-forms-unread' : '' ?>">
                        <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= $fsId ?>" form="fsBulkForm" data-fs-check
                                   aria-label="<?= $fsT('forms.inbox.select_one', $fsSender) ?>"></td>
                        <td>
                            <a class="cms-forms-sender" href="<?= $fsE(FormSubmissionsModule::url(['view' => $fsId])) ?>"><?= $fsE($fsSender) ?></a>
                            <?php if ($fsItem['sender_email'] !== '' && $fsItem['sender_name'] !== ''): ?>
                                <div class="small text-secondary"><?= $fsE($fsItem['sender_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $fsSubject !== '' ? $fsE($fsSubject) : '<span class="text-secondary">–</span>' ?>
                            <?php if ((int) $fsItem['file_count'] > 0): ?>
                                <span class="badge bg-secondary-lt ms-1" title="<?= $fsT('forms.inbox.files_count', (int) $fsItem['file_count']) ?>"><i class="ti ti-paperclip" aria-hidden="true"></i> <?= (int) $fsItem['file_count'] ?><span class="visually-hidden"> <?= $fsT('forms.inbox.files') ?></span></span>
                            <?php endif; ?>
                        </td>
                        <td><?= $fsE($fsItem['form_title'] !== '' ? $fsItem['form_title'] : '–') ?></td>
                        <td class="text-nowrap"><?= $fsE($fsDate((string) $fsItem['created_at'])) ?></td>
                        <td><span class="badge <?= $fsE($fsStatusBadge[$fsStatus] ?? 'bg-secondary-lt') ?>"><?= $fsT('forms.status.' . $fsStatus) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($fsPages > 1 || ($fsIsTrash && $fsCanManage)): ?>
            <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                <?php if ($fsIsTrash && $fsCanManage): ?>
                    <form method="post" action="/admin/form-submissions"
                          data-confirm-title="<?= $fsT('forms.inbox.empty_trash') ?>" data-confirm-message="<?= $fsT('forms.inbox.empty_trash_confirm') ?>"
                          data-confirm-text="<?= $fsT('forms.inbox.empty_trash') ?>" data-confirm-class="btn-danger">
                        <input type="hidden" name="csrf_token" value="<?= $fsE($csrfToken) ?>">
                        <input type="hidden" name="action" value="empty_trash">
                        <?= $fsHiddenFilter ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger"><?= $fsT('forms.inbox.empty_trash') ?></button>
                    </form>
                <?php endif; ?>
                <?php if ($fsPages > 1): ?>
                    <nav class="ms-auto" aria-label="<?= $fsT('forms.inbox.pagination') ?>">
                        <ul class="pagination m-0">
                            <li class="page-item<?= $fsPage <= 1 ? ' disabled' : '' ?>">
                                <a class="page-link" href="<?= $fsE($fsUrl(['page' => max(1, $fsPage - 1)])) ?>"<?= $fsPage <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>><?= $fsT('forms.inbox.prev') ?></a>
                            </li>
                            <li class="page-item active" aria-current="page"><span class="page-link"><?= $fsT('forms.inbox.page_of', $fsPage, $fsPages) ?></span></li>
                            <li class="page-item<?= $fsPage >= $fsPages ? ' disabled' : '' ?>">
                                <a class="page-link" href="<?= $fsE($fsUrl(['page' => min($fsPages, $fsPage + 1)])) ?>"<?= $fsPage >= $fsPages ? ' aria-disabled="true" tabindex="-1"' : '' ?>><?= $fsT('forms.inbox.next') ?></a>
                            </li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
