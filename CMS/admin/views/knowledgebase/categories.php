<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Knowledgebase – Kategorien anlegen, umbenennen (Einträge ziehen mit), sortieren, löschen.
 *
 * @var array<string, mixed> $kbData
 * @var string $csrfToken
 * @var callable $kbE
 * @var callable $kbT
 */

$kbList = (array) ($kbData['categories'] ?? []);
$kbEdit = is_array($kbData['edit'] ?? null) ? $kbData['edit'] : null;
$kbAction = $kbE(KnowledgebaseModule::url(['section' => 'categories']));
?>
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $kbT('knowledgebase.admin.tab_categories') ?></h3></div>
            <?php if ($kbList === []): ?>
                <div class="card-body text-secondary"><?= $kbT('knowledgebase.admin.categories_empty') ?></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-vcenter card-table">
                        <thead><tr><th><?= $kbT('knowledgebase.admin.field_name') ?></th><th class="text-end"><?= $kbT('knowledgebase.admin.col_active_entries') ?></th><th class="text-end"><?= $kbT('knowledgebase.admin.field_sort') ?></th><th class="w-1"><span class="visually-hidden"><?= $kbT('knowledgebase.admin.col_actions') ?></span></th></tr></thead>
                        <tbody>
                        <?php foreach ($kbList as $kbCategory): ?>
                            <?php $kbName = (string) ($kbCategory['category'] ?? ''); ?>
                            <tr>
                                <td>
                                    <a href="<?= $kbE(KnowledgebaseModule::url(['section' => 'categories', 'edit' => (int) $kbCategory['id']])) ?>"><?= $kbE($kbName) ?></a>
                                    <div class="small text-secondary"><code><?= $kbE($kbCategory['slug'] ?? '') ?></code></div>
                                </td>
                                <td class="text-end"><a href="<?= $kbE(KnowledgebaseModule::url(['section' => 'entries', 'category' => $kbName])) ?>"><?= (int) ($kbCategory['entry_count'] ?? 0) ?></a></td>
                                <td class="text-end"><?= (int) ($kbCategory['sort_order'] ?? 0) ?></td>
                                <td>
                                    <form method="post" action="<?= $kbAction ?>" class="d-inline"
                                          data-confirm-title="<?= $kbT('knowledgebase.admin.category_delete_title') ?>" data-confirm-message="<?= $kbE(sprintf((string) __('knowledgebase.admin.category_delete_confirm'), $kbName)) ?>"
                                          data-confirm-text="<?= $kbT('knowledgebase.admin.action_delete') ?>" data-confirm-class="btn-danger">
                                        <input type="hidden" name="csrf_token" value="<?= $kbE($csrfToken) ?>">
                                        <input type="hidden" name="action" value="delete_category">
                                        <input type="hidden" name="category_id" value="<?= (int) $kbCategory['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"><?= $kbT('knowledgebase.admin.action_delete') ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <form method="post" action="<?= $kbAction ?>" class="card">
            <input type="hidden" name="csrf_token" value="<?= $kbE($csrfToken) ?>">
            <input type="hidden" name="action" value="save_category">
            <input type="hidden" name="category_id" value="<?= $kbEdit !== null ? (int) $kbEdit['id'] : 0 ?>">
            <div class="card-header"><h3 class="card-title"><?= $kbT($kbEdit !== null ? 'knowledgebase.admin.category_edit' : 'knowledgebase.admin.category_new') ?></h3></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label required" for="kbCatName"><?= $kbT('knowledgebase.admin.field_name') ?></label>
                    <input type="text" class="form-control" id="kbCatName" name="category_name" maxlength="120" required value="<?= $kbE($kbEdit['category'] ?? '') ?>"<?= $kbEdit !== null ? ' aria-describedby="kbCatNameHint"' : '' ?>>
                    <?php if ($kbEdit !== null): ?><div class="form-hint" id="kbCatNameHint"><?= $kbT('knowledgebase.admin.category_rename_hint') ?></div><?php endif; ?>
                </div>
                <div>
                    <label class="form-label" for="kbCatSort"><?= $kbT('knowledgebase.admin.field_sort') ?></label>
                    <input type="number" class="form-control" id="kbCatSort" name="sort_order" min="0" max="9999" step="1" value="<?= (int) ($kbEdit['sort_order'] ?? 0) ?>">
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <?php if ($kbEdit !== null): ?><a class="btn" href="<?= $kbAction ?>"><?= $kbT('knowledgebase.admin.cancel') ?></a><?php endif; ?>
                <button type="submit" class="btn btn-primary ms-auto"><?= $kbT('knowledgebase.admin.save') ?></button>
            </div>
        </form>
    </div>
</div>
