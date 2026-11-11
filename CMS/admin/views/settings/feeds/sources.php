<?php
/**
 * Feeds → Quellen: Liste mit Filtern und Massenaktionen, Formular für neue/bearbeitete Quellen.
 */

declare(strict_types=1);

use CMS\Services\Feed\AggregatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

$fdCategories = $feedsData['categories'] ?? [];
$fdFilters = $feedsData['filters'] ?? [];
$fdSource = is_array($feedsData['source'] ?? null) ? $feedsData['source'] : null;

if (!empty($feedsData['form'])) :
    $fdId = (int) ($fdSource['id'] ?? 0);
    if ($fdCategories === []) : ?>
        <div class="alert alert-warning mt-3"><?= $feedsT('sources.need_category') ?> <a href="<?= $feedsUrl(['section' => 'categories', 'new' => 1]) ?>"><?= $feedsT('categories.new') ?></a></div>
    <?php else : ?>
    <form method="post" action="<?= $feedsUrl(['section' => 'sources']) ?>" class="card mt-3">
        <?= $feedsCsrfField ?>
        <input type="hidden" name="action" value="save_source">
        <input type="hidden" name="id" value="<?= $fdId ?>">
        <div class="card-header">
            <h2 class="card-title"><?= $fdId > 0 ? $feedsT('sources.edit_title', (string) $fdSource['name']) : $feedsT('sources.new') ?></h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label required" for="fd-source-url"><?= $feedsT('sources.feed_url') ?></label>
                    <input class="form-control" type="url" id="fd-source-url" name="feed_url" required maxlength="2048" value="<?= $feedsEscape((string) ($fdSource['feed_url'] ?? '')) ?>" placeholder="https://example.com/feed/">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="fd-source-name"><?= $feedsT('sources.name') ?></label>
                    <input class="form-control" type="text" id="fd-source-name" name="name" maxlength="255" value="<?= $feedsEscape((string) ($fdSource['name'] ?? '')) ?>">
                    <div class="form-hint"><?= $feedsT('sources.name_hint') ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label required" for="fd-source-category"><?= $feedsT('sources.category') ?></label>
                    <select class="form-select" id="fd-source-category" name="category_id" required>
                        <?php foreach ($fdCategories as $fdCategory) : ?>
                            <option value="<?= (int) $fdCategory['id'] ?>"<?= (int) ($fdSource['category_id'] ?? (int) ($fdFilters['category_id'] ?? 0)) === (int) $fdCategory['id'] ? ' selected' : '' ?>><?= $feedsEscape((string) $fdCategory['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="fd-source-site"><?= $feedsT('sources.site_url') ?></label>
                    <input class="form-control" type="url" id="fd-source-site" name="site_url" maxlength="2048" value="<?= $feedsEscape((string) ($fdSource['site_url'] ?? '')) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="fd-source-description"><?= $feedsT('sources.description') ?></label>
                    <textarea class="form-control" id="fd-source-description" name="description" rows="2" maxlength="1000"><?= $feedsEscape((string) ($fdSource['description'] ?? '')) ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="fd-source-interval"><?= $feedsT('sources.interval') ?></label>
                    <select class="form-select" id="fd-source-interval" name="fetch_interval">
                        <?php foreach ([15, 30, 60, 120, 180, 360, 720, 1440] as $fdMinutes) : ?>
                            <option value="<?= $fdMinutes ?>"<?= (int) ($fdSource['fetch_interval'] ?? 60) === $fdMinutes ? ' selected' : '' ?>><?= $feedsT($fdMinutes < 60 ? 'sources.minutes' : 'sources.hours', $fdMinutes < 60 ? $fdMinutes : intdiv($fdMinutes, 60)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="fd-source-max"><?= $feedsT('sources.max_items') ?></label>
                    <input class="form-control" type="number" id="fd-source-max" name="max_items" min="1" max="500" value="<?= (int) ($fdSource['max_items'] ?? 50) ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <div>
                        <label class="form-check form-switch mb-1">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1"<?= $fdSource === null || (int) $fdSource['is_active'] === 1 ? ' checked' : '' ?>>
                            <span class="form-check-label"><?= $feedsT('sources.active') ?></span>
                        </label>
                        <?php if ($fdId === 0) : ?>
                            <label class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="fetch_now" value="1" checked>
                                <span class="form-check-label"><?= $feedsT('sources.fetch_after_save') ?></span>
                            </label>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php if ($fdSource !== null && trim((string) ($fdSource['last_error'] ?? '')) !== '') : ?>
                <div class="alert alert-danger mt-3 mb-0"><?= $feedsT('sources.last_error', $feedsDate($fdSource['last_error_at'] ?? ''), (string) $fdSource['last_error']) ?></div>
            <?php endif; ?>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a class="btn btn-link" href="<?= $feedsUrl(['section' => 'sources']) ?>"><?= $feedsT('back') ?></a>
            <button class="btn btn-primary" type="submit"><?= $feedsEscape((string) __('Save Changes')) ?></button>
        </div>
    </form>
    <?php endif;
    return;
endif;
?>
<div class="card mt-3">
    <div class="card-header flex-wrap gap-2">
        <form method="get" action="/admin/settings" class="d-flex flex-wrap gap-2 me-auto" role="search">
            <input type="hidden" name="tab" value="feeds">
            <input type="hidden" name="section" value="sources">
            <select class="form-select form-select-sm w-auto" name="cat" aria-label="<?= $feedsT('sources.category') ?>">
                <option value=""><?= $feedsT('filter.all_categories') ?></option>
                <?php foreach ($fdCategories as $fdCategory) : ?>
                    <option value="<?= (int) $fdCategory['id'] ?>"<?= (int) ($fdFilters['category_id'] ?? 0) === (int) $fdCategory['id'] ? ' selected' : '' ?>><?= $feedsEscape((string) $fdCategory['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="form-select form-select-sm w-auto" name="status" aria-label="<?= $feedsT('filter.status') ?>">
                <option value=""><?= $feedsT('filter.all_status') ?></option>
                <?php foreach (['active', 'inactive', 'error'] as $fdStatus) : ?>
                    <option value="<?= $fdStatus ?>"<?= ($fdFilters['status'] ?? '') === $fdStatus ? ' selected' : '' ?>><?= $feedsT('sources.status_' . $fdStatus) ?></option>
                <?php endforeach; ?>
            </select>
            <input class="form-control form-control-sm w-auto" type="search" name="q" value="<?= $feedsEscape((string) ($fdFilters['search'] ?? '')) ?>" placeholder="<?= $feedsT('filter.search') ?>" aria-label="<?= $feedsT('filter.search') ?>">
            <button class="btn btn-sm" type="submit"><?= $feedsT('filter.apply') ?></button>
        </form>
        <a class="btn btn-primary btn-sm" href="<?= $feedsUrl(['section' => 'sources', 'new' => 1]) ?>"><?= $feedsT('sources.new') ?></a>
    </div>
    <?php if (($feedsData['sources'] ?? []) === []) : ?>
        <div class="card-body text-secondary"><?= $feedsT('sources.empty') ?></div>
    <?php else : ?>
        <form method="post" action="<?= $feedsUrl(['section' => 'sources']) ?>" id="fdSourcesBulk" class="card-body border-bottom py-2 d-flex flex-wrap align-items-center gap-2" data-fd-bulk data-fd-delete-confirm="<?= $feedsT('sources.bulk_delete_confirm') ?>">
            <?= $feedsCsrfField ?>
            <input type="hidden" name="action" value="bulk_sources">
            <label class="visually-hidden" for="fdSourcesBulkAction"><?= $feedsT('bulk.label') ?></label>
            <select class="form-select form-select-sm w-auto" id="fdSourcesBulkAction" name="bulk" required>
                <option value=""><?= $feedsT('bulk.label') ?></option>
                <?php foreach (['fetch', 'activate', 'deactivate', 'delete'] as $fdBulk) : ?>
                    <option value="<?= $fdBulk ?>"><?= $feedsT('bulk.' . $fdBulk) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-sm"><?= $feedsT('bulk.apply') ?></button>
        </form>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1"><input class="form-check-input" type="checkbox" data-fd-check-all="fdSourcesBulk" aria-label="<?= $feedsT('bulk.select_all') ?>"></th>
                        <th><?= $feedsT('sources.name') ?></th>
                        <th><?= $feedsT('sources.category') ?></th>
                        <th><?= $feedsT('sources.col_items') ?></th>
                        <th><?= $feedsT('sources.col_fetched') ?></th>
                        <th class="w-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedsData['sources'] as $fdRow) :
                        $fdError = trim((string) ($fdRow['last_error'] ?? '')); ?>
                        <tr>
                            <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $fdRow['id'] ?>" form="fdSourcesBulk" data-fd-check aria-label="<?= $feedsEscape((string) $fdRow['name']) ?>"></td>
                            <td>
                                <a href="<?= $feedsUrl(['section' => 'sources', 'edit' => (int) $fdRow['id']]) ?>" class="fw-bold"><?= $feedsEscape((string) $fdRow['name']) ?></a>
                                <?php if ((int) $fdRow['is_active'] !== 1) : ?><span class="badge bg-secondary-lt ms-1"><?= $feedsT('sources.status_inactive') ?></span><?php endif; ?>
                                <div class="small text-secondary text-truncate cms-fd-url"><?= $feedsEscape((string) $fdRow['feed_url']) ?></div>
                                <?php if ($fdError !== '') : ?><div class="small text-danger"><?= $feedsEscape($fdError) ?></div><?php endif; ?>
                            </td>
                            <td><?= $feedsEscape((string) ($fdRow['category_name'] ?? '')) ?></td>
                            <td><a href="<?= $feedsUrl(['section' => 'items', 'source' => (int) $fdRow['id']]) ?>"><?= (int) $fdRow['item_count'] ?></a></td>
                            <td class="text-nowrap<?= AggregatorRepository::isOverdue($fdRow) && (int) $fdRow['is_active'] === 1 ? ' text-warning' : '' ?>"><?= $feedsEscape($feedsDate($fdRow['last_fetched_at'] ?? '')) ?></td>
                            <td>
                                <form method="post" action="<?= $feedsUrl(['section' => 'sources']) ?>">
                                    <?= $feedsCsrfField ?>
                                    <input type="hidden" name="action" value="fetch_source">
                                    <input type="hidden" name="id" value="<?= (int) $fdRow['id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary text-nowrap" type="submit"><?= $feedsT('sources.fetch_now') ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
