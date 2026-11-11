<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Kategorien verwalten.
 *
 * @var array<string, mixed> $dlData
 * @var string $csrfToken
 * @var callable $dlE
 * @var callable $dlT
 */

$dlList = (array) ($dlData['list'] ?? []);
$dlEdit = is_array($dlData['edit'] ?? null) ? $dlData['edit'] : null;
$dlV = static fn (string $key): string => $dlEdit !== null && is_scalar($dlEdit[$key] ?? null) ? (string) $dlEdit[$key] : '';
?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <?php if ($dlList === []): ?>
                <div class="card-body text-secondary"><?= $dlT('downloads.admin.categories_empty') ?></div>
            <?php else: ?>
                <table class="table table-vcenter card-table">
                    <thead><tr><th><?= $dlT('downloads.admin.field_name') ?></th><th class="text-end"><?= $dlT('downloads.admin.col_downloads') ?></th><th><?= $dlT('downloads.admin.col_status') ?></th><th class="w-1"></th></tr></thead>
                    <tbody>
                    <?php foreach ($dlList as $dlCategory): ?>
                        <tr>
                            <td><?= $dlE(($dlCategory['icon'] ?? '') !== '' ? $dlCategory['icon'] . ' ' : '') ?><a href="<?= $dlE(DownloadsModule::url(['tab' => 'categories', 'edit' => (int) $dlCategory['id']])) ?>"><?= $dlE($dlCategory['name']) ?></a><div class="small text-secondary"><code><?= $dlE($dlCategory['slug']) ?></code></div></td>
                            <td class="text-end"><a href="<?= $dlE(DownloadsModule::url(['category' => (int) $dlCategory['id']])) ?>"><?= (int) ($dlCategory['item_count'] ?? 0) ?></a></td>
                            <td><?= $dlT(($dlCategory['status'] ?? 'active') === 'active' ? 'downloads.admin.category_active' : 'downloads.admin.category_inactive') ?></td>
                            <td>
                                <form method="post" action="/admin/downloads?tab=categories" class="d-inline"
                                      data-confirm-title="<?= $dlT('downloads.admin.category_delete_title') ?>" data-confirm-message="<?= $dlE(sprintf((string) __('downloads.admin.category_delete_confirm'), (string) $dlCategory['name'])) ?>"
                                      data-confirm-text="<?= $dlT('downloads.admin.action_delete') ?>" data-confirm-class="btn-danger">
                                    <input type="hidden" name="csrf_token" value="<?= $dlE($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="category_id" value="<?= (int) $dlCategory['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><?= $dlT('downloads.admin.action_delete') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <form method="post" action="/admin/downloads?tab=categories" class="card">
            <input type="hidden" name="csrf_token" value="<?= $dlE($csrfToken) ?>">
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="category_id" value="<?= $dlEdit !== null ? (int) $dlEdit['id'] : 0 ?>">
            <div class="card-header"><h3 class="card-title"><?= $dlT($dlEdit !== null ? 'downloads.admin.category_edit' : 'downloads.admin.category_new') ?></h3></div>
            <div class="card-body">
                <div class="mb-3"><label class="form-label required" for="dlCatName"><?= $dlT('downloads.admin.field_name') ?></label><input type="text" class="form-control" id="dlCatName" name="name" maxlength="190" required value="<?= $dlE($dlV('name')) ?>"></div>
                <div class="mb-3"><label class="form-label" for="dlCatSlug"><?= $dlT('downloads.admin.field_slug') ?></label><input type="text" class="form-control" id="dlCatSlug" name="slug" maxlength="190" value="<?= $dlE($dlV('slug')) ?>"></div>
                <div class="mb-3"><label class="form-label" for="dlCatDesc"><?= $dlT('downloads.admin.field_description') ?></label><textarea class="form-control" id="dlCatDesc" name="description" rows="3" maxlength="2000"><?= $dlE($dlV('description')) ?></textarea></div>
                <div class="row g-3">
                    <div class="col-6"><label class="form-label" for="dlCatIcon"><?= $dlT('downloads.admin.field_icon') ?></label><input type="text" class="form-control" id="dlCatIcon" name="icon" maxlength="64" value="<?= $dlE($dlV('icon')) ?>"></div>
                    <div class="col-6"><label class="form-label" for="dlCatSort"><?= $dlT('downloads.admin.field_sort') ?></label><input type="number" class="form-control" id="dlCatSort" name="sort_order" step="1" value="<?= (int) ($dlEdit['sort_order'] ?? 0) ?>"></div>
                </div>
                <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="inactive" value="1"<?= ($dlEdit['status'] ?? 'active') === 'inactive' ? ' checked' : '' ?>><span class="form-check-label"><?= $dlT('downloads.admin.category_hide') ?></span></label>
            </div>
            <div class="card-footer d-flex gap-2">
                <?php if ($dlEdit !== null): ?><a class="btn" href="/admin/downloads?tab=categories"><?= $dlT('downloads.admin.cancel') ?></a><?php endif; ?>
                <button type="submit" class="btn btn-primary ms-auto"><?= $dlT('downloads.admin.save') ?></button>
            </div>
        </form>
    </div>
</div>
