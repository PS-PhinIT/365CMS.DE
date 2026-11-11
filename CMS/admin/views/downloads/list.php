<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Liste mit Filtern, Suche, Sammelaktionen und Seitenwechsel.
 *
 * Die Sammelaktion ist ein eigenes Formular (`dlBulkForm`); die Auswahl-Kästchen gehören über das
 * `form`-Attribut dazu (keine verschachtelten Formulare).
 *
 * @var array<string, mixed> $dlData
 * @var string $csrfToken
 * @var callable $dlE
 * @var callable $dlT
 * @var callable $dlDate
 * @var callable $dlNumber
 * @var array<string, string> $dlStatusBadge
 */

$dlFilters = is_array($dlData['filters'] ?? null) ? $dlData['filters'] : DownloadsModule::filters([]);
$dlItems = is_array($dlData['items'] ?? null) ? $dlData['items'] : [];
$dlCounts = is_array($dlData['counts'] ?? null) ? $dlData['counts'] : [];
$dlCategories = is_array($dlData['categories'] ?? null) ? $dlData['categories'] : [];
$dlPages = (int) ($dlData['pages'] ?? 1);
$dlIsTrash = $dlFilters['status'] === 'trash';
$dlUrl = static fn (array $overrides = []): string => DownloadsModule::url(array_merge(
    ['status' => $dlFilters['status'], 'category' => $dlFilters['category'], 'access' => $dlFilters['access'], 'type' => $dlFilters['type'], 'q' => $dlFilters['q']],
    $overrides
));
$dlStatusTabs = ['all' => 'downloads.admin.filter_all', 'published' => 'downloads.status.published', 'draft' => 'downloads.status.draft', 'archived' => 'downloads.status.archived', 'trash' => 'downloads.status.trash'];
$dlBulk = $dlIsTrash
    ? ['restore' => 'downloads.admin.action_restore', 'delete' => 'downloads.admin.action_delete']
    : ['publish' => 'downloads.admin.bulk_publish', 'draft' => 'downloads.admin.bulk_draft', 'archive' => 'downloads.admin.bulk_archive', 'category' => 'downloads.admin.bulk_category', 'access' => 'downloads.admin.bulk_access', 'trash' => 'downloads.admin.action_trash'];
?>

<div class="card">
    <div class="card-header flex-wrap gap-2">
        <ul class="nav nav-pills card-header-pills">
            <?php foreach ($dlStatusTabs as $dlKey => $dlLabel): ?>
                <?php if ($dlKey === 'trash' && (int) ($dlCounts['trash'] ?? 0) === 0 && !$dlIsTrash) { continue; } ?>
                <li class="nav-item">
                    <a class="nav-link<?= $dlFilters['status'] === $dlKey ? ' active' : '' ?>"<?= $dlFilters['status'] === $dlKey ? ' aria-current="true"' : '' ?> href="<?= $dlE($dlUrl(['status' => $dlKey])) ?>">
                        <?= $dlT($dlLabel) ?> <span class="badge bg-secondary-lt ms-1"><?= (int) ($dlCounts[$dlKey] ?? 0) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <form method="get" action="/admin/downloads" class="ms-auto d-flex flex-wrap gap-2" role="search">
            <?php if ($dlFilters['status'] !== 'all'): ?><input type="hidden" name="status" value="<?= $dlE($dlFilters['status']) ?>"><?php endif; ?>
            <select class="form-select form-select-sm w-auto" name="category" aria-label="<?= $dlT('downloads.admin.col_category') ?>">
                <option value="0"><?= $dlT('downloads.admin.all_categories') ?></option>
                <?php foreach ($dlCategories as $dlCategory): ?>
                    <option value="<?= (int) $dlCategory['id'] ?>"<?= (int) $dlFilters['category'] === (int) $dlCategory['id'] ? ' selected' : '' ?>><?= $dlE($dlCategory['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm w-auto" name="access" aria-label="<?= $dlT('downloads.admin.col_access') ?>">
                <option value=""><?= $dlT('downloads.admin.all_access') ?></option>
                <?php foreach (\CMS\Services\Downloads\DownloadSettings::ACCESS_LEVELS as $dlLevel): ?>
                    <option value="<?= $dlE($dlLevel) ?>"<?= $dlFilters['access'] === $dlLevel ? ' selected' : '' ?>><?= $dlT('downloads.access.' . $dlLevel) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (($dlData['file_types'] ?? []) !== []): ?>
                <select class="form-select form-select-sm w-auto" name="type" aria-label="<?= $dlT('downloads.admin.col_type') ?>">
                    <option value=""><?= $dlT('downloads.admin.all_types') ?></option>
                    <?php foreach ($dlData['file_types'] as $dlType): ?>
                        <option value="<?= $dlE($dlType) ?>"<?= $dlFilters['type'] === $dlType ? ' selected' : '' ?>><?= $dlE(strtoupper((string) $dlType)) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <input type="search" class="form-control form-control-sm w-auto" name="q" value="<?= $dlE($dlFilters['q']) ?>" maxlength="100" placeholder="<?= $dlT('downloads.admin.search') ?>" aria-label="<?= $dlT('downloads.admin.search') ?>">
            <button type="submit" class="btn btn-sm"><?= $dlT('downloads.admin.filter') ?></button>
        </form>
    </div>

    <?php if ($dlItems === []): ?>
        <div class="card-body text-secondary"><?= $dlT($dlFilters['q'] !== '' || $dlFilters['category'] > 0 || $dlFilters['access'] !== '' || $dlFilters['type'] !== '' ? 'downloads.admin.empty_filtered' : ($dlIsTrash ? 'downloads.admin.empty_trash' : 'downloads.admin.empty')) ?></div>
    <?php else: ?>
        <form method="post" action="/admin/downloads" id="dlBulkForm" class="card-body border-bottom py-2 d-flex flex-wrap align-items-center gap-2" data-dl-bulk
              data-delete-title="<?= $dlT('downloads.admin.delete_title') ?>" data-delete-message="<?= $dlT('downloads.admin.bulk_delete_confirm') ?>" data-delete-text="<?= $dlT('downloads.admin.action_delete') ?>">
            <input type="hidden" name="csrf_token" value="<?= $dlE($csrfToken) ?>">
            <input type="hidden" name="action" value="bulk">
            <?php foreach (['status', 'category', 'access', 'type', 'q', 'page'] as $dlKey): ?>
                <input type="hidden" name="filter[<?= $dlKey ?>]" value="<?= $dlE((string) $dlFilters[$dlKey]) ?>">
            <?php endforeach; ?>
            <label class="visually-hidden" for="dlBulkAction"><?= $dlT('downloads.admin.bulk_label') ?></label>
            <select class="form-select form-select-sm w-auto" id="dlBulkAction" name="bulk_action" required data-dl-bulk-select>
                <option value=""><?= $dlT('downloads.admin.bulk_label') ?></option>
                <?php foreach ($dlBulk as $dlKey => $dlLabel): ?>
                    <option value="<?= $dlE($dlKey) ?>"><?= $dlT($dlLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$dlIsTrash): ?>
                <span class="cms-dl-bulk-extra" data-dl-bulk-extra="category" hidden>
                    <select class="form-select form-select-sm w-auto" name="bulk_category" aria-label="<?= $dlT('downloads.admin.col_category') ?>">
                        <option value="0"><?= $dlT('downloads.admin.no_category') ?></option>
                        <?php foreach ($dlCategories as $dlCategory): ?>
                            <option value="<?= (int) $dlCategory['id'] ?>"><?= $dlE($dlCategory['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
                <span class="cms-dl-bulk-extra" data-dl-bulk-extra="access" hidden>
                    <select class="form-select form-select-sm w-auto" name="bulk_access" aria-label="<?= $dlT('downloads.admin.col_access') ?>">
                        <?php foreach (['public', 'logged_in', 'member'] as $dlLevel): ?>
                            <option value="<?= $dlE($dlLevel) ?>"><?= $dlT('downloads.access.' . $dlLevel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>
            <?php endif; ?>
            <button type="submit" class="btn btn-sm"><?= $dlT('downloads.admin.bulk_apply') ?></button>
            <span class="text-secondary small ms-auto"><?= $dlT('downloads.admin.total', (int) ($dlData['total'] ?? 0)) ?></span>
        </form>

        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1"><input type="checkbox" class="form-check-input" data-dl-check-all aria-label="<?= $dlT('downloads.admin.select_all') ?>"></th>
                        <th><?= $dlT('downloads.admin.col_title') ?></th>
                        <th><?= $dlT('downloads.admin.col_version') ?></th>
                        <th><?= $dlT('downloads.admin.col_category') ?></th>
                        <th><?= $dlT('downloads.admin.col_access') ?></th>
                        <th class="text-end"><?= $dlT('downloads.admin.col_downloads') ?></th>
                        <th class="text-end"><?= $dlT('downloads.admin.col_usage') ?></th>
                        <th><?= $dlT('downloads.admin.col_updated') ?></th>
                        <th><?= $dlT('downloads.admin.col_status') ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($dlItems as $dlItem): ?>
                    <?php $dlId = (int) $dlItem['id']; $dlStatus = (string) $dlItem['status']; ?>
                    <tr>
                        <td><input type="checkbox" class="form-check-input" name="ids[]" value="<?= $dlId ?>" form="dlBulkForm" data-dl-check aria-label="<?= $dlT('downloads.admin.select_one', (string) $dlItem['title']) ?>"></td>
                        <td>
                            <a class="fw-medium" href="<?= $dlE(DownloadsModule::url(['action' => 'edit', 'id' => $dlId])) ?>"><?= $dlE($dlItem['title']) ?></a>
                            <div class="small text-secondary"><code><?= $dlE($dlItem['slug']) ?></code><?php if ((string) $dlItem['file_type'] !== ''): ?> · <?= $dlE(strtoupper((string) $dlItem['file_type'])) ?><?php endif; ?></div>
                        </td>
                        <td class="text-nowrap"><?= (string) ($dlItem['current_version'] ?? '') !== '' ? $dlE($dlItem['current_version']) : '<span class="text-warning">' . $dlT('downloads.admin.no_version') . '</span>' ?></td>
                        <td><?= $dlE((string) ($dlItem['category_name'] ?? '') !== '' ? $dlItem['category_name'] : '–') ?></td>
                        <td class="text-nowrap"><?= $dlT('downloads.access.' . (string) $dlItem['access_level']) ?></td>
                        <td class="text-end text-nowrap"><?= $dlNumber((int) $dlItem['download_count']) ?> <span class="text-secondary small">/ <?= $dlNumber((int) $dlItem['hits_30']) ?></span></td>
                        <td class="text-end"><?php if ((int) $dlItem['usage_count'] > 0): ?><a href="<?= $dlE(DownloadsModule::url(['action' => 'edit', 'id' => $dlId, 'section' => 'usage'])) ?>"><?= (int) $dlItem['usage_count'] ?></a><?php else: ?>0<?php endif; ?></td>
                        <td class="text-nowrap"><?= $dlE($dlDate((string) ($dlItem['updated_at'] ?? ''))) ?></td>
                        <td><span class="badge <?= $dlE($dlStatusBadge[$dlStatus] ?? 'bg-secondary-lt') ?>"><?= $dlT('downloads.status.' . $dlStatus) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer d-flex flex-wrap align-items-center gap-2">
            <span class="text-secondary small"><?= $dlT('downloads.admin.downloads_hint') ?></span>
            <?php if ($dlPages > 1): ?>
                <nav class="ms-auto" aria-label="<?= $dlT('downloads.archive.pager') ?>">
                    <ul class="pagination m-0">
                        <li class="page-item<?= $dlFilters['page'] <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= $dlE($dlUrl(['page' => max(1, $dlFilters['page'] - 1)])) ?>"><?= $dlT('downloads.archive.prev') ?></a></li>
                        <li class="page-item active" aria-current="page"><span class="page-link"><?= $dlT('downloads.archive.page_of', (int) $dlFilters['page'], $dlPages) ?></span></li>
                        <li class="page-item<?= $dlFilters['page'] >= $dlPages ? ' disabled' : '' ?>"><a class="page-link" href="<?= $dlE($dlUrl(['page' => min($dlPages, $dlFilters['page'] + 1)])) ?>"><?= $dlT('downloads.archive.next') ?></a></li>
                    </ul>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
