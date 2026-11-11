<?php
/**
 * Feeds → Artikel: abgerufene Einträge filtern, hervorheben, ausblenden, löschen.
 */

declare(strict_types=1);

use CMS\Services\Feed\AggregatorFrontendController;

if (!defined('ABSPATH')) {
    exit;
}

$fdFilters = $feedsData['filters'] ?? [];
$fdQuery = [
    'section' => 'items',
    'cat' => (int) ($fdFilters['category_id'] ?? 0) ?: null,
    'source' => (int) ($fdFilters['source_id'] ?? 0) ?: null,
    'status' => (string) ($fdFilters['status'] ?? ''),
    'q' => (string) ($fdFilters['search'] ?? ''),
];
$fdReturn = FeedAggregatorModule::url($fdQuery + ['page' => (int) ($feedsData['page'] ?? 1) > 1 ? (int) $feedsData['page'] : null]);
?>
<div class="card mt-3">
    <div class="card-header flex-wrap gap-2">
        <form method="get" action="/admin/settings" class="d-flex flex-wrap gap-2" role="search">
            <input type="hidden" name="tab" value="feeds">
            <input type="hidden" name="section" value="items">
            <select class="form-select form-select-sm w-auto" name="cat" aria-label="<?= $feedsT('sources.category') ?>">
                <option value=""><?= $feedsT('filter.all_categories') ?></option>
                <?php foreach ($feedsData['categories'] ?? [] as $fdCategory) : ?>
                    <option value="<?= (int) $fdCategory['id'] ?>"<?= (int) ($fdFilters['category_id'] ?? 0) === (int) $fdCategory['id'] ? ' selected' : '' ?>><?= $feedsEscape((string) $fdCategory['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm w-auto" name="source" aria-label="<?= $feedsT('items.col_source') ?>">
                <option value=""><?= $feedsT('filter.all_sources') ?></option>
                <?php foreach ($feedsData['sources'] ?? [] as $fdSource) : ?>
                    <option value="<?= (int) $fdSource['id'] ?>"<?= (int) ($fdFilters['source_id'] ?? 0) === (int) $fdSource['id'] ? ' selected' : '' ?>><?= $feedsEscape((string) $fdSource['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm w-auto" name="status" aria-label="<?= $feedsT('filter.status') ?>">
                <option value=""><?= $feedsT('filter.all_status') ?></option>
                <?php foreach (['visible', 'hidden', 'featured'] as $fdStatus) : ?>
                    <option value="<?= $fdStatus ?>"<?= ($fdFilters['status'] ?? '') === $fdStatus ? ' selected' : '' ?>><?= $feedsT('items.status_' . $fdStatus) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="form-control form-control-sm w-auto" type="search" name="q" value="<?= $feedsEscape((string) ($fdFilters['search'] ?? '')) ?>" placeholder="<?= $feedsT('filter.search') ?>" aria-label="<?= $feedsT('filter.search') ?>">
            <button class="btn btn-sm" type="submit"><?= $feedsT('filter.apply') ?></button>
        </form>
        <span class="ms-auto text-secondary small"><?= $feedsT('items.total', (int) ($feedsData['total'] ?? 0)) ?></span>
    </div>
    <?php if (($feedsData['items'] ?? []) === []) : ?>
        <div class="card-body text-secondary"><?= $feedsT('items.empty') ?></div>
    <?php else : ?>
        <form method="post" action="<?= $feedsUrl(['section' => 'items']) ?>" id="fdItemsBulk" class="card-body border-bottom py-2 d-flex flex-wrap align-items-center gap-2" data-fd-bulk data-fd-delete-confirm="<?= $feedsT('items.bulk_delete_confirm') ?>">
            <?= $feedsCsrfField ?>
            <input type="hidden" name="action" value="bulk_items">
            <input type="hidden" name="return" value="<?= $feedsEscape($fdReturn) ?>">
            <label class="visually-hidden" for="fdItemsBulkAction"><?= $feedsT('bulk.label') ?></label>
            <select class="form-select form-select-sm w-auto" id="fdItemsBulkAction" name="bulk" required>
                <option value=""><?= $feedsT('bulk.label') ?></option>
                <?php foreach (['feature', 'unfeature', 'hide', 'show', 'delete'] as $fdBulk) : ?>
                    <option value="<?= $fdBulk ?>"><?= $feedsT('bulk.' . $fdBulk) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm"><?= $feedsT('bulk.apply') ?></button>
        </form>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1"><input class="form-check-input" type="checkbox" data-fd-check-all="fdItemsBulk" aria-label="<?= $feedsT('bulk.select_all') ?>"></th>
                        <th><?= $feedsT('items.col_title') ?></th>
                        <th><?= $feedsT('items.col_source') ?></th>
                        <th><?= $feedsT('items.col_date') ?></th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedsData['items'] as $fdItem) :
                        $fdLink = AggregatorFrontendController::safeUrl($fdItem['link'] ?? ''); ?>
                        <tr<?= (int) $fdItem['is_hidden'] === 1 ? ' class="text-secondary"' : '' ?>>
                            <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $fdItem['id'] ?>" form="fdItemsBulk" data-fd-check aria-label="<?= $feedsEscape((string) $fdItem['title']) ?>"></td>
                            <td>
                                <?php if ($fdLink !== '') : ?>
                                    <a href="<?= $feedsEscape($fdLink) ?>" target="_blank" rel="noopener noreferrer nofollow"><?= $feedsEscape((string) $fdItem['title']) ?></a>
                                <?php else : ?>
                                    <?= $feedsEscape((string) $fdItem['title']) ?>
                                <?php endif; ?>
                                <?php if ((int) $fdItem['is_featured'] === 1) : ?><span class="badge bg-yellow-lt ms-1"><?= $feedsT('items.status_featured') ?></span><?php endif; ?>
                                <?php if ((int) $fdItem['is_hidden'] === 1) : ?><span class="badge bg-secondary-lt ms-1"><?= $feedsT('items.status_hidden') ?></span><?php endif; ?>
                                <div class="small text-secondary"><?= $feedsEscape((string) ($fdItem['category_name'] ?? '')) ?></div>
                            </td>
                            <td><?= $feedsEscape((string) $fdItem['source_name']) ?></td>
                            <td class="text-nowrap"><?= $feedsEscape($feedsDate($fdItem['pub_date'])) ?></td>
                            <td class="text-nowrap">
                                <?php foreach (['featured' => (int) $fdItem['is_featured'] === 1 ? 'items.unfeature' : 'items.feature', 'hidden' => (int) $fdItem['is_hidden'] === 1 ? 'items.show' : 'items.hide'] as $fdFlag => $fdLabel) : ?>
                                    <form method="post" action="<?= $feedsUrl(['section' => 'items']) ?>" class="d-inline">
                                        <?= $feedsCsrfField ?>
                                        <input type="hidden" name="action" value="item_flag">
                                        <input type="hidden" name="flag" value="<?= $fdFlag ?>">
                                        <input type="hidden" name="id" value="<?= (int) $fdItem['id'] ?>">
                                        <input type="hidden" name="return" value="<?= $feedsEscape($fdReturn) ?>">
                                        <button class="btn btn-sm btn-ghost-secondary" type="submit"><?= $feedsT($fdLabel) ?></button>
                                    </form>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ((int) ($feedsData['pages'] ?? 1) > 1) : ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <?php $fdPage = (int) $feedsData['page']; ?>
                <?php if ($fdPage > 1) : ?><a class="btn btn-sm" href="<?= $feedsUrl($fdQuery + ['page' => $fdPage - 1]) ?>"><?= $feedsT('prev') ?></a><?php else : ?><span></span><?php endif; ?>
                <span class="text-secondary small"><?= $feedsT('page_of', $fdPage, (int) $feedsData['pages']) ?></span>
                <?php if ($fdPage < (int) $feedsData['pages']) : ?><a class="btn btn-sm" href="<?= $feedsUrl($fdQuery + ['page' => $fdPage + 1]) ?>"><?= $feedsT('next') ?></a><?php else : ?><span></span><?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
