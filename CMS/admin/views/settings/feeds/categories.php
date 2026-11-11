<?php
/**
 * Feeds → Bereiche: öffentliche Themenseiten unter /feed/<slug> mit Layout und Seitengröße.
 */

declare(strict_types=1);

use CMS\Services\Feed\AggregatorFrontendController;
use CMS\Services\Feed\AggregatorSettings;

if (!defined('ABSPATH')) {
    exit;
}

$fdCategory = is_array($feedsData['category'] ?? null) ? $feedsData['category'] : null;

if (!empty($feedsData['form'])) :
    $fdId = (int) ($fdCategory['id'] ?? 0); ?>
    <form method="post" action="<?= $feedsUrl(['section' => 'categories']) ?>" class="card mt-3">
        <?= $feedsCsrfField ?>
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="id" value="<?= $fdId ?>">
        <div class="card-header"><h2 class="card-title"><?= $fdId > 0 ? $feedsT('categories.edit_title', (string) $fdCategory['name']) : $feedsT('categories.new') ?></h2></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label required" for="fd-cat-name"><?= $feedsT('categories.name') ?></label>
                    <input class="form-control" type="text" id="fd-cat-name" name="name" required maxlength="150" value="<?= $feedsEscape((string) ($fdCategory['name'] ?? '')) ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="fd-cat-name-en"><?= $feedsT('categories.name_en') ?></label>
                    <input class="form-control" type="text" id="fd-cat-name-en" name="name_en" maxlength="150" value="<?= $feedsEscape((string) ($fdCategory['name_en'] ?? '')) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="fd-cat-icon"><?= $feedsT('categories.icon') ?></label>
                    <input class="form-control" type="text" id="fd-cat-icon" name="icon" maxlength="10" value="<?= $feedsEscape((string) ($fdCategory['icon'] ?? '📰')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="fd-cat-slug"><?= $feedsT('categories.slug') ?></label>
                    <div class="input-group">
                        <span class="input-group-text">/feed/</span>
                        <input class="form-control" type="text" id="fd-cat-slug" name="slug" maxlength="120" pattern="[a-z0-9-]*" value="<?= $feedsEscape((string) ($fdCategory['slug'] ?? '')) ?>">
                    </div>
                    <div class="form-hint"><?= $feedsT('categories.slug_hint') ?></div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="fd-cat-layout"><?= $feedsT('categories.layout') ?></label>
                    <select class="form-select" id="fd-cat-layout" name="layout">
                        <?php foreach (AggregatorSettings::LAYOUTS as $fdLayout) : ?>
                            <option value="<?= $fdLayout ?>"<?= ($fdCategory['layout'] ?? 'grid') === $fdLayout ? ' selected' : '' ?>><?= $feedsT('categories.layout_' . $fdLayout) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="fd-cat-per-page"><?= $feedsT('categories.per_page') ?></label>
                    <input class="form-control" type="number" id="fd-cat-per-page" name="items_per_page" min="4" max="100" value="<?= (int) ($fdCategory['items_per_page'] ?? 20) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="fd-cat-desc"><?= $feedsT('categories.description') ?></label>
                    <textarea class="form-control" id="fd-cat-desc" name="description" rows="2" maxlength="2000"><?= $feedsEscape((string) ($fdCategory['description'] ?? '')) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="fd-cat-desc-en"><?= $feedsT('categories.description_en') ?></label>
                    <textarea class="form-control" id="fd-cat-desc-en" name="description_en" rows="2" maxlength="2000"><?= $feedsEscape((string) ($fdCategory['description_en'] ?? '')) ?></textarea>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="fd-cat-sort"><?= $feedsT('categories.sort_order') ?></label>
                    <input class="form-control" type="number" id="fd-cat-sort" name="sort_order" value="<?= (int) ($fdCategory['sort_order'] ?? 0) ?>">
                </div>
                <div class="col-md-9 d-flex align-items-end">
                    <label class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_public" value="1"<?= $fdCategory === null || (int) $fdCategory['is_public'] === 1 ? ' checked' : '' ?>>
                        <span class="form-check-label"><?= $feedsT('categories.public') ?></span>
                    </label>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-between">
            <a class="btn btn-link" href="<?= $feedsUrl(['section' => 'categories']) ?>"><?= $feedsT('back') ?></a>
            <button class="btn btn-primary" type="submit"><?= $feedsEscape((string) __('Save Changes')) ?></button>
        </div>
    </form>
    <?php return;
endif;
?>
<div class="card mt-3">
    <div class="card-header">
        <h2 class="card-title me-auto"><?= $feedsT('nav.categories') ?></h2>
        <a class="btn btn-primary btn-sm" href="<?= $feedsUrl(['section' => 'categories', 'new' => 1]) ?>"><?= $feedsT('categories.new') ?></a>
    </div>
    <?php if (($feedsData['categories'] ?? []) === []) : ?>
        <div class="card-body text-secondary"><?= $feedsT('categories.empty') ?> <a href="<?= $feedsUrl(['section' => 'import']) ?>"><?= $feedsT('categories.empty_catalog') ?></a></div>
    <?php else : ?>
        <form method="post" action="<?= $feedsUrl(['section' => 'categories']) ?>" id="fdCategoriesBulk" class="card-body border-bottom py-2 d-flex flex-wrap align-items-center gap-2" data-fd-bulk data-fd-delete-confirm="<?= $feedsT('categories.delete_confirm') ?>">
            <?= $feedsCsrfField ?>
            <input type="hidden" name="action" value="bulk_categories">
            <label class="visually-hidden" for="fdCategoriesBulkAction"><?= $feedsT('bulk.label') ?></label>
            <select class="form-select form-select-sm w-auto" id="fdCategoriesBulkAction" name="bulk" required>
                <option value=""><?= $feedsT('bulk.label') ?></option>
                <option value="delete"><?= $feedsT('bulk.delete') ?></option>
            </select>
            <button type="submit" class="btn btn-sm"><?= $feedsT('bulk.apply') ?></button>
        </form>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th class="w-1"><input class="form-check-input" type="checkbox" data-fd-check-all="fdCategoriesBulk" aria-label="<?= $feedsT('bulk.select_all') ?>"></th>
                        <th><?= $feedsT('categories.name') ?></th>
                        <th><?= $feedsT('categories.slug') ?></th>
                        <th><?= $feedsT('categories.layout') ?></th>
                        <th><?= $feedsT('nav.sources') ?></th>
                        <th><?= $feedsT('nav.items') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($feedsData['categories'] as $fdRow) : ?>
                        <tr>
                            <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $fdRow['id'] ?>" form="fdCategoriesBulk" data-fd-check aria-label="<?= $feedsEscape((string) $fdRow['name']) ?>"></td>
                            <td>
                                <span aria-hidden="true"><?= $feedsEscape((string) ($fdRow['icon'] ?? '')) ?></span>
                                <a class="fw-bold" href="<?= $feedsUrl(['section' => 'categories', 'edit' => (int) $fdRow['id']]) ?>"><?= $feedsEscape((string) $fdRow['name']) ?></a>
                                <?php if ((int) $fdRow['is_public'] !== 1) : ?><span class="badge bg-secondary-lt ms-1"><?= $feedsT('categories.private') ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int) $fdRow['is_public'] === 1 && !empty($feedsData['enabled'])) : ?>
                                    <a href="<?= $feedsEscape(AggregatorFrontendController::categoryPath((string) $fdRow['slug'], 'de')) ?>" target="_blank" rel="noopener">/feed/<?= $feedsEscape((string) $fdRow['slug']) ?></a>
                                <?php else : ?>
                                    /feed/<?= $feedsEscape((string) $fdRow['slug']) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $feedsT('categories.layout_' . (in_array($fdRow['layout'], AggregatorSettings::LAYOUTS, true) ? $fdRow['layout'] : 'grid')) ?></td>
                            <td><a href="<?= $feedsUrl(['section' => 'sources', 'cat' => (int) $fdRow['id']]) ?>"><?= (int) $fdRow['source_count'] ?></a></td>
                            <td><a href="<?= $feedsUrl(['section' => 'items', 'cat' => (int) $fdRow['id']]) ?>"><?= (int) $fdRow['item_count'] ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
