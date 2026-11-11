<?php
/**
 * Feeds → Katalog & OPML: kuratierte Vorlagen übernehmen, OPML-Dateien importieren und exportieren.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$fdCategories = $feedsData['categories'] ?? [];
$fdCategorySelect = static function (string $id) use ($fdCategories, $feedsT, $feedsEscape): string {
    $html = '<select class="form-select form-select-sm" id="' . $feedsEscape($id) . '" name="category_id"><option value="0">' . $feedsT('import.new_category') . '</option>';
    foreach ($fdCategories as $category) {
        $html .= '<option value="' . (int) $category['id'] . '">' . $feedsEscape((string) $category['name']) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="row row-cards mt-1">
    <div class="col-lg-6">
        <form method="post" action="<?= $feedsUrl(['section' => 'import']) ?>" enctype="multipart/form-data" class="card">
            <?= $feedsCsrfField ?>
            <input type="hidden" name="action" value="import_opml">
            <div class="card-header"><h2 class="card-title"><?= $feedsT('import.opml_title') ?></h2></div>
            <div class="card-body">
                <p class="text-secondary"><?= $feedsT('import.opml_intro') ?></p>
                <div class="mb-3">
                    <label class="form-label required" for="fd-opml-file"><?= $feedsT('import.opml_file') ?></label>
                    <input class="form-control" type="file" id="fd-opml-file" name="opml" accept=".opml,.xml,text/xml,text/x-opml,application/xml" required>
                </div>
                <div>
                    <label class="form-label" for="fd-opml-category"><?= $feedsT('import.target') ?></label>
                    <?= str_replace('<option value="0">' . $feedsT('import.new_category') . '</option>', '<option value="0">' . $feedsT('import.opml_groups') . '</option>', $fdCategorySelect('fd-opml-category')) ?>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between">
                <a class="btn btn-outline-secondary" href="<?= $feedsUrl(['section' => 'import', 'export' => 'opml']) ?>"><?= $feedsT('import.opml_export') ?></a>
                <button class="btn btn-primary" type="submit"><?= $feedsT('import.opml_submit') ?></button>
            </div>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h2 class="card-title"><?= $feedsT('import.catalog_title') ?></h2></div>
            <div class="card-body text-secondary"><?= $feedsT('import.catalog_intro') ?></div>
        </div>
    </div>
</div>

<div class="row row-cards mt-1">
    <?php foreach ($feedsData['catalog'] ?? [] as $fdKey => $fdTemplate) :
        $fdFormId = 'fd-catalog-' . preg_replace('/[^a-z0-9-]/', '', (string) $fdKey); ?>
        <div class="col-md-6 col-xl-4">
            <form method="post" action="<?= $feedsUrl(['section' => 'import']) ?>" class="card h-100" id="<?= $feedsEscape($fdFormId) ?>">
                <?= $feedsCsrfField ?>
                <input type="hidden" name="action" value="import_catalog">
                <input type="hidden" name="catalog_key" value="<?= $feedsEscape((string) $fdKey) ?>">
                <div class="card-header">
                    <h3 class="card-title"><span aria-hidden="true"><?= $feedsEscape((string) $fdTemplate['icon']) ?></span> <?= $feedsEscape((string) $fdTemplate['name']) ?></h3>
                </div>
                <div class="card-body">
                    <p class="text-secondary small"><?= $feedsEscape((string) $fdTemplate['description']) ?></p>
                    <details>
                        <summary><?= $feedsT('import.catalog_choose', count($fdTemplate['feeds'])) ?></summary>
                        <div class="mt-2 cms-fd-catalog-list">
                            <?php foreach ($fdTemplate['feeds'] as $fdIndex => $fdFeed) : ?>
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="feeds[]" value="<?= (int) $fdIndex + 1 ?>">
                                    <span class="form-check-label"><?= $feedsEscape((string) $fdFeed['name']) ?></span>
                                    <span class="d-block small text-secondary"><?= $feedsEscape((string) ($fdFeed['description'] ?? '')) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-hint"><?= $feedsT('import.catalog_none_selected') ?></div>
                    </details>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2 align-items-center">
                    <label class="visually-hidden" for="<?= $feedsEscape($fdFormId) ?>-cat"><?= $feedsT('import.target') ?></label>
                    <div class="flex-grow-1"><?= $fdCategorySelect($fdFormId . '-cat') ?></div>
                    <button class="btn btn-primary btn-sm" type="submit"><?= $feedsT('import.catalog_submit') ?></button>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
</div>
