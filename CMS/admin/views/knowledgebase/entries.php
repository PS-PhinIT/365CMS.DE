<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledgebase – Einträge: Suche, Filter (Kategorie, Status), Liste mit Blätterfunktion.
 * „Löschen“ legt den Eintrag in den Papierkorb.
 *
 * @var array<string, mixed> $kbData
 * @var string $csrfToken
 * @var callable $kbE
 * @var callable $kbT
 * @var callable $kbDate
 * @var callable $kbNumber
 */

$kbFilters = (array) ($kbData['filters'] ?? []);
$kbEntries = (array) ($kbData['entries'] ?? []);
$kbPage = (int) ($kbData['page'] ?? 1);
$kbPages = (int) ($kbData['pages'] ?? 1);
$kbTotal = (int) ($kbData['total'] ?? 0);
$kbBase = ['section' => 'entries', 'q' => $kbFilters['search'] ?? '', 'category' => $kbFilters['category'] ?? '', 'status' => $kbFilters['status'] ?? ''];
?>
<form method="get" action="/admin/knowledgebase" class="card mb-4" role="search">
    <input type="hidden" name="section" value="entries">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label" for="kbFilterQ"><?= $kbT('knowledgebase.admin.filter_search') ?></label>
            <input type="search" class="form-control" id="kbFilterQ" name="q" maxlength="120" value="<?= $kbE($kbFilters['search'] ?? '') ?>" placeholder="<?= $kbT('knowledgebase.admin.filter_search_placeholder') ?>">
        </div>
        <div class="col-sm-6 col-md-3">
            <label class="form-label" for="kbFilterCategory"><?= $kbT('knowledgebase.admin.col_category') ?></label>
            <select class="form-select" id="kbFilterCategory" name="category">
                <option value=""><?= $kbT('knowledgebase.admin.filter_all') ?></option>
                <?php foreach ((array) ($kbData['categories'] ?? []) as $kbCategory): ?>
                    <?php $kbCategoryName = (string) ($kbCategory['category'] ?? ''); ?>
                    <option value="<?= $kbE($kbCategoryName) ?>"<?= $kbCategoryName === ($kbFilters['category'] ?? '') ? ' selected' : '' ?>><?= $kbE($kbCategoryName) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-md-2">
            <label class="form-label" for="kbFilterStatus"><?= $kbT('knowledgebase.admin.col_status') ?></label>
            <select class="form-select" id="kbFilterStatus" name="status">
                <option value=""><?= $kbT('knowledgebase.admin.filter_all') ?></option>
                <option value="active"<?= ($kbFilters['status'] ?? '') === 'active' ? ' selected' : '' ?>><?= $kbT('knowledgebase.admin.status_active') ?></option>
                <option value="inactive"<?= ($kbFilters['status'] ?? '') === 'inactive' ? ' selected' : '' ?>><?= $kbT('knowledgebase.admin.status_inactive') ?></option>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill"><?= $kbT('knowledgebase.admin.filter_apply') ?></button>
            <?php if (($kbFilters['search'] ?? '') !== '' || ($kbFilters['category'] ?? '') !== '' || ($kbFilters['status'] ?? '') !== ''): ?>
                <a class="btn" href="<?= $kbE(KnowledgebaseModule::url(['section' => 'entries'])) ?>" title="<?= $kbT('knowledgebase.admin.filter_reset') ?>" aria-label="<?= $kbT('knowledgebase.admin.filter_reset') ?>"><i class="ti ti-x" aria-hidden="true"></i></a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= $kbT('knowledgebase.admin.entries_count', $kbNumber($kbTotal)) ?></h3>
    </div>
    <?php if ($kbEntries === []): ?>
        <div class="card-body text-secondary"><?= $kbT($kbTotal === 0 && ($kbFilters['search'] ?? '') === '' && ($kbFilters['category'] ?? '') === '' && ($kbFilters['status'] ?? '') === '' ? 'knowledgebase.admin.empty_entries' : 'knowledgebase.admin.empty_filtered') ?></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                <tr>
                    <th><?= $kbT('knowledgebase.admin.col_title') ?></th>
                    <th><?= $kbT('knowledgebase.admin.col_keyword') ?></th>
                    <th><?= $kbT('knowledgebase.admin.col_category') ?></th>
                    <th class="text-end"><?= $kbT('knowledgebase.admin.col_priority') ?></th>
                    <th><?= $kbT('knowledgebase.admin.col_status') ?></th>
                    <th class="w-1"><span class="visually-hidden"><?= $kbT('knowledgebase.admin.col_actions') ?></span></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($kbEntries as $kbRow): ?>
                    <?php $kbActive = (int) ($kbRow['is_active'] ?? 0) === 1; ?>
                    <tr>
                        <td>
                            <a href="<?= $kbE(KnowledgebaseModule::url(['section' => 'edit', 'id' => (int) $kbRow['id']])) ?>" class="fw-medium"><?= $kbE($kbRow['title'] ?? '') ?></a>
                            <div class="small text-secondary"><code><?= $kbE($kbRow['slug'] ?? '') ?></code></div>
                        </td>
                        <td><?= $kbE($kbRow['keyword'] ?? '') ?></td>
                        <td class="text-secondary"><?= $kbE(($kbRow['category'] ?? '') !== '' ? $kbRow['category'] : '–') ?></td>
                        <td class="text-end"><?= (int) ($kbRow['priority'] ?? 100) ?></td>
                        <td><span class="badge <?= $kbActive ? 'bg-green-lt' : 'bg-secondary-lt' ?>"><?= $kbT($kbActive ? 'knowledgebase.admin.status_active' : 'knowledgebase.admin.status_inactive') ?></span></td>
                        <td>
                            <div class="btn-list flex-nowrap">
                                <a class="btn btn-sm" href="<?= $kbE(KnowledgebaseModule::url(['section' => 'edit', 'id' => (int) $kbRow['id']])) ?>"><?= $kbT('knowledgebase.admin.action_edit') ?></a>
                                <?php if ($kbActive): ?>
                                    <a class="btn btn-sm btn-icon" href="/kb/<?= $kbE(rawurlencode((string) ($kbRow['slug'] ?? ''))) ?>" target="_blank" rel="noopener" title="<?= $kbT('knowledgebase.admin.view_public') ?>" aria-label="<?= $kbT('knowledgebase.admin.view_public') ?>"><i class="ti ti-external-link" aria-hidden="true"></i></a>
                                <?php endif; ?>
                                <form method="post" action="<?= $kbE(KnowledgebaseModule::url(['section' => 'entries'])) ?>" class="d-inline"
                                      data-confirm-title="<?= $kbT('knowledgebase.admin.trash_title') ?>" data-confirm-message="<?= $kbE(sprintf((string) __('knowledgebase.admin.trash_confirm'), (string) ($kbRow['title'] ?? ''))) ?>"
                                      data-confirm-text="<?= $kbT('knowledgebase.admin.action_trash') ?>" data-confirm-class="btn-danger">
                                    <input type="hidden" name="csrf_token" value="<?= $kbE($csrfToken) ?>">
                                    <input type="hidden" name="action" value="trash_entry">
                                    <input type="hidden" name="entry_id" value="<?= (int) $kbRow['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="<?= $kbT('knowledgebase.admin.action_trash') ?>" aria-label="<?= $kbT('knowledgebase.admin.action_trash') ?>"><i class="ti ti-trash" aria-hidden="true"></i></button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($kbPages > 1): ?>
            <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                <p class="m-0 text-secondary"><?= $kbT('knowledgebase.admin.page_of', $kbPage, $kbPages) ?></p>
                <ul class="pagination m-0 ms-auto">
                    <li class="page-item<?= $kbPage <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= $kbE(KnowledgebaseModule::url($kbBase + ['page' => $kbPage - 1])) ?>"<?= $kbPage <= 1 ? ' tabindex="-1" aria-disabled="true"' : '' ?>><i class="ti ti-chevron-left" aria-hidden="true"></i><?= $kbT('knowledgebase.admin.prev') ?></a></li>
                    <li class="page-item<?= $kbPage >= $kbPages ? ' disabled' : '' ?>"><a class="page-link" href="<?= $kbE(KnowledgebaseModule::url($kbBase + ['page' => $kbPage + 1])) ?>"<?= $kbPage >= $kbPages ? ' tabindex="-1" aria-disabled="true"' : '' ?>><?= $kbT('knowledgebase.admin.next') ?><i class="ti ti-chevron-right" aria-hidden="true"></i></a></li>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
