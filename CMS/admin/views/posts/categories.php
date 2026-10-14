<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$categories = $data['categories'] ?? [];
$categoryOptions = $data['categoryOptions'] ?? [];
$counts = $data['counts'] ?? [];
$editCategory = $editCategory ?? null;
$formValues = is_array($formValues ?? null) ? $formValues : [];
$formAlert = is_array($formAlert ?? null) ? $formAlert : null;

$editCategoryId = (int) ($formValues['cat_id'] ?? ($editCategory['id'] ?? 0));
$editCategoryName = (string) ($formValues['cat_name'] ?? ($editCategory['name'] ?? ''));
$editCategorySlug = (string) ($formValues['cat_slug'] ?? ($editCategory['slug'] ?? ''));
$editCategorySlugEn = (string) ($formValues['cat_slug_en'] ?? ($editCategory['slug_en'] ?? ''));
$editCategoryDescription = (string) ($formValues['cat_description'] ?? ($editCategory['description'] ?? ''));
$editCategoryParentId = (int) ($formValues['parent_id'] ?? ($editCategory['parent_id'] ?? 0));
$editCategoryReplacementId = (int) ($formValues['replacement_category_id'] ?? ($editCategory['replacement_category_id'] ?? 0));
$isEditing = $editCategoryId > 0;
$deleteCategoryOptions = array_values(array_filter(
    $categoryOptions,
    static fn(array $categoryOption): bool => (int) ($categoryOption['id'] ?? 0) > 0
));
$deleteCategorySubmitDisabled = count($deleteCategoryOptions) <= 1;
$replacementCategoryDeleteCount = count(array_filter(
    $categories,
    static fn(array $category): bool => (int) ($category['replacement_category_id'] ?? 0) > 0
));
$replacementCategoryDeletePreview = array_values(array_filter(array_map(
    static function (array $category): string {
        if ((int) ($category['replacement_category_id'] ?? 0) <= 0) {
            return '';
        }

        return trim((string) ($category['name'] ?? ''));
    },
    $categories
)));
$replacementCategoryDeletePreview = array_slice($replacementCategoryDeletePreview, 0, 5);
$buildCategoryArchivePreviewPaths = static function (string $slug): array {
    $slug = trim((string) $slug, '/');
    if ($slug === '' || !function_exists('cms_get_archive_locales') || !function_exists('cms_get_archive_base')) {
        return [];
    }

    $paths = [];
    foreach (cms_get_archive_locales() as $locale) {
        $archiveBase = trim((string) cms_get_archive_base('category', (string) $locale), '/');
        if ($archiveBase === '') {
            continue;
        }

        $path = '/' . $archiveBase . '/' . $slug;
        if (in_array($path, $paths, true)) {
            continue;
        }

        $paths[] = $path;
    }

    return $paths;
};
$categoryArchivePreviewPaths = $buildCategoryArchivePreviewPaths($editCategorySlug);
$panelState = [
    'open' => $isEditing || !empty($formAlert),
    'mode' => $isEditing ? 'edit' : 'create',
    'successMessage' => (is_array($alert ?? null) && (($alert['type'] ?? '') === 'success')) ? (string) ($alert['message'] ?? '') : '',
    'formError' => is_array($formAlert) ? [
        'message' => (string) ($formAlert['message'] ?? 'Kategorie konnte nicht gespeichert werden.'),
        'details' => array_values(array_filter(array_map('strval', (array) ($formAlert['details'] ?? [])), static fn(string $detail): bool => trim($detail) !== '')),
    ] : null,
    'values' => [
        'cat_id' => $editCategoryId,
        'cat_name' => $editCategoryName,
        'cat_slug' => $editCategorySlug,
        'cat_slug_en' => $editCategorySlugEn,
        'cat_description' => $editCategoryDescription,
        'parent_id' => $editCategoryParentId,
        'replacement_category_id' => $editCategoryReplacementId,
    ],
];
$panelStateJson = htmlspecialchars((string) json_encode($panelState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Seiten &amp; Beiträge</div>
                <h2 class="page-title mb-1">Beitrags-Kategorien</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int) ($counts['total'] ?? 0); ?> Kategorien</span>
                    <span><?php echo (int) ($counts['assigned_posts'] ?? 0); ?> Beitragszuweisungen</span>
                </div>
            </div>
            <button type="button" class="btn btn-primary d-print-none" data-taxonomy-open>
                <i class="ti ti-plus" style="font-size:15px;" aria-hidden="true"></i>
                Neue Kategorie anlegen
            </button>
        </div>
    </div>
</div>

<div class="page-body" data-taxonomy-panel-root data-taxonomy-list-url="/admin/post-categories">
    <div class="container-xl">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-3'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>
        <div class="content-taxonomy-list">
                <div class="card content-listing-card content-listing-toolbar content-entity-toolbar">
                    <div class="card-body">
                        <div class="content-listing-toolbar__label">Filter &amp; Aktionen</div>
                        <div class="content-entity-toolbar__grid">
                            <div class="content-entity-toolbar__group">
                                <label class="form-label mb-0 small text-secondary" for="bulkCategoryReplacementSelect">Ersatzkategorie für Auswahl</label>
                                <select class="form-select form-select-sm" id="bulkCategoryReplacementSelect" form="bulkCategoryForm" name="bulk_replacement_category_id" aria-label="Gemeinsame Ersatzkategorie">
                                    <option value="0">Ersatz je Kategorie nutzen</option>
                                    <?php foreach ($deleteCategoryOptions as $categoryOption): ?>
                                        <option value="<?php echo (int) ($categoryOption['id'] ?? 0); ?>">
                                            <?php echo htmlspecialchars((string) ($categoryOption['option_label'] ?? $categoryOption['name'] ?? ''), ENT_QUOTES); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="content-entity-toolbar__actions">
                                <button type="submit" form="bulkCategoryForm" class="btn btn-outline-danger btn-sm">Ausgewählte löschen</button>
                                <?php if ($replacementCategoryDeleteCount > 0): ?>
                                    <form method="post" class="d-inline-flex js-delete-replacement-categories-form"
                                          data-delete-count="<?php echo $replacementCategoryDeleteCount; ?>"
                                          data-delete-preview="<?php echo htmlspecialchars(implode('|', $replacementCategoryDeletePreview), ENT_QUOTES); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
                                        <input type="hidden" name="action" value="delete_categories_with_replacement">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <?php echo $replacementCategoryDeleteCount; ?> Kategorien mit Ersatz löschen
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card content-listing-card content-entity-list-card">
                      <form method="post" id="bulkCategoryForm"
                          data-confirm-title="Kategorien gesammelt löschen"
                          data-confirm-message="Ausgewählte Kategorien wirklich löschen? Kategorien mit Beitragsbezug werden nur gelöscht, wenn eine gültige Ersatzkategorie vorhanden ist."
                          data-confirm-text="Kategorien löschen"
                          data-confirm-class="btn-danger"
                          data-confirm-status-class="bg-danger"></form>
                    <div class="card-header">
                        <div>
                            <h3 class="card-title content-entity-card-title">Vorhandene Kategorien</h3>
                            <p class="content-entity-card-subtitle">Ausgewählte Kategorien können gesammelt gelöscht werden; bei Beitragsbezug ist eine Ersatzkategorie Pflicht.</p>
                        </div>
                    </div>
                    <input type="hidden" form="bulkCategoryForm" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
                    <input type="hidden" form="bulkCategoryForm" name="action" value="bulk_delete_categories">
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table content-listing-table content-entity-table">
                            <thead>
                                <tr>
                                    <th class="w-1">Auswahl</th>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Ebene</th>
                                    <th>Ersatz</th>
                                    <th>Beiträge</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($categories === []): ?>
                                    <tr><td colspan="7" class="text-secondary text-center py-4">Noch keine Kategorien vorhanden.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($categories as $category): ?>
                                    <tr class="content-listing-table__row">
                                        <td>
                                            <input class="form-check-input" type="checkbox" form="bulkCategoryForm" name="category_ids[]" value="<?php echo (int) ($category['id'] ?? 0); ?>" aria-label="Kategorie auswählen: <?php echo htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES); ?>">
                                        </td>
                                        <td class="fw-medium"><?php echo htmlspecialchars((string) ($category['option_label'] ?? $category['name'] ?? ''), ENT_QUOTES); ?></td>
                                        <td>
                                            <code><?php echo htmlspecialchars((string) ($category['slug'] ?? ''), ENT_QUOTES); ?></code>
                                            <?php if (trim((string) ($category['slug_en'] ?? '')) !== ''): ?>
                                                <div class="text-secondary small">EN: <code><?php echo htmlspecialchars((string) $category['slug_en'], ENT_QUOTES); ?></code></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($category['is_main_category'])): ?>
                                                <span class="badge bg-azure-lt text-azure">Hauptkategorie</span>
                                            <?php else: ?>
                                                <span class="text-secondary"><?php echo htmlspecialchars((string) ($category['parent_name'] ?? 'Unterkategorie'), ENT_QUOTES); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php $replacementName = trim((string) ($category['replacement_category_name'] ?? '')); ?>
                                            <?php if ($replacementName === ''): ?>
                                                <span class="text-secondary">—</span>
                                            <?php else: ?>
                                                <span><?php echo htmlspecialchars($replacementName, ENT_QUOTES); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div><?php echo (int) ($category['post_count_total'] ?? 0); ?></div>
                                            <?php if ((int) ($category['post_count_total'] ?? 0) !== (int) ($category['post_count_direct'] ?? 0)): ?>
                                                <div class="text-secondary small">direkt: <?php echo (int) ($category['post_count_direct'] ?? 0); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="table-actions content-listing-table__actions-cell">
                                            <div class="table-row-actions">
                                                <a href="<?php echo htmlspecialchars('/admin/post-categories?edit=' . (int) ($category['id'] ?? 0), ENT_QUOTES); ?>" class="btn btn-outline-secondary btn-sm">Bearbeiten</a>
                                                <form method="post" class="js-delete-category-form"
                                                      data-category-id="<?php echo (int) ($category['id'] ?? 0); ?>"
                                                      data-category-name="<?php echo htmlspecialchars((string) ($category['name'] ?? ''), ENT_QUOTES); ?>"
                                                    data-assigned-posts="<?php echo (int) ($category['assigned_post_count'] ?? $category['post_count_direct'] ?? 0); ?>"
                                                    data-default-replacement-category-id="<?php echo (int) ($category['replacement_category_id'] ?? 0); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
                                                    <input type="hidden" name="action" value="delete_category">
                                                    <input type="hidden" name="cat_id" value="<?php echo (int) ($category['id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-ghost-danger btn-sm">Löschen</button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
        </div>
    </div>
</div>

<div class="taxonomy-slide-backdrop" data-taxonomy-backdrop></div>
<aside class="taxonomy-slide-panel" data-taxonomy-panel aria-hidden="true">
    <form method="post" action="/admin/post-categories" data-taxonomy-form novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
        <input type="hidden" name="action" value="save_category">
        <input type="hidden" name="cat_id" value="<?php echo $editCategoryId; ?>" data-taxonomy-field="cat_id">
        <input type="hidden" name="replacement_category_id" value="<?php echo $editCategoryReplacementId; ?>" data-taxonomy-field="replacement_category_id">
        <div class="taxonomy-slide-panel__header">
            <h3 class="card-title mb-0" data-taxonomy-title><?php echo $isEditing ? 'Kategorie bearbeiten' : 'Neue Kategorie anlegen'; ?></h3>
            <button type="button" class="btn btn-icon btn-ghost-secondary" data-taxonomy-close aria-label="Schließen">
                <i class="ti ti-x" style="font-size:18px;" aria-hidden="true"></i>
            </button>
        </div>
        <div class="taxonomy-slide-panel__body">
            <div class="alert alert-danger taxonomy-form-error d-none" data-taxonomy-form-error role="alert"></div>
            <div class="mb-3">
                <label class="form-label required" for="postCategoryPanelName">Name</label>
                <input type="text" class="form-control" id="postCategoryPanelName" name="cat_name" value="<?php echo htmlspecialchars($editCategoryName, ENT_QUOTES); ?>" data-taxonomy-field="cat_name" data-taxonomy-name required>
                <div class="taxonomy-validation-error d-none" data-taxonomy-error-for="cat_name"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="postCategoryPanelSlug">Slug</label>
                <div class="input-group">
                    <span class="input-group-text">/</span>
                    <input type="text" class="form-control" id="postCategoryPanelSlug" name="cat_slug" value="<?php echo htmlspecialchars($editCategorySlug, ENT_QUOTES); ?>" placeholder="wird automatisch generiert" data-taxonomy-field="cat_slug" data-taxonomy-slug>
                </div>
                <div class="taxonomy-validation-error d-none" data-taxonomy-error-for="cat_slug"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="postCategoryPanelSlugEn">Slug (EN)</label>
                <div class="input-group">
                    <span class="input-group-text">/</span>
                    <input type="text" class="form-control" id="postCategoryPanelSlugEn" name="cat_slug_en" value="<?php echo htmlspecialchars($editCategorySlugEn, ENT_QUOTES); ?>" placeholder="optional – für englische Archiv-URLs" data-taxonomy-field="cat_slug_en">
                </div>
                <div class="form-hint">Optionaler zweiter Slug für das englische Kategorie-Archiv. Leer lassen, um den Haupt-Slug für alle Sprachen zu nutzen.</div>
                <div class="taxonomy-validation-error d-none" data-taxonomy-error-for="cat_slug_en"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="postCategoryPanelDescription">Beschreibung</label>
                <textarea class="form-control" id="postCategoryPanelDescription" rows="3" name="cat_description" maxlength="2000" data-taxonomy-field="cat_description"><?php echo htmlspecialchars($editCategoryDescription, ENT_QUOTES); ?></textarea>
            </div>
            <div class="mb-3">
                <label class="form-label" for="postCategoryPanelParent">Übergeordnete Kategorie</label>
                <select class="form-select" id="postCategoryPanelParent" name="parent_id" data-taxonomy-field="parent_id">
                    <option value="0">Keine</option>
                    <?php foreach ($categoryOptions as $categoryOption): ?>
                        <?php $optionId = (int) ($categoryOption['id'] ?? 0); ?>
                        <?php if ($optionId === $editCategoryId) { continue; } ?>
                        <option value="<?php echo $optionId; ?>" <?php if ($editCategoryParentId === $optionId) echo 'selected'; ?>>
                            <?php echo htmlspecialchars((string) ($categoryOption['option_label'] ?? $categoryOption['name'] ?? ''), ENT_QUOTES); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="taxonomy-slide-panel__footer">
            <button type="button" class="btn btn-outline-secondary" data-taxonomy-cancel>Abbrechen</button>
            <button type="submit" class="btn btn-primary" data-taxonomy-submit>Anlegen</button>
        </div>
    </form>
    <div hidden data-taxonomy-panel-state="<?php echo $panelStateJson; ?>"></div>
</aside>

<div class="modal modal-blur fade" id="deleteCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="deleteCategoryModalForm">
                <div class="modal-header">
                    <h5 class="modal-title">Kategorie löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="delete_category">
                    <input type="hidden" name="cat_id" id="deleteCategoryId" value="0">

                    <p class="mb-2">Die Kategorie <strong id="deleteCategoryName"></strong> wird gelöscht.</p>
                    <p class="text-secondary mb-0" id="deleteCategoryHint">Unterkategorien werden dabei zu Hauptkategorien.</p>
                    <p class="mt-3 mb-0 fw-medium d-none" id="deleteCategoryQuestion">In welche Ersatzkategorie sollen die zugeordneten Artikel verschoben werden?</p>

                    <div class="mt-3 d-none" id="deleteCategoryReassignWrap">
                        <label class="form-label" for="replacementCategoryId">Neue Kategorie für betroffene Beiträge</label>
                        <select class="form-select" id="replacementCategoryId" name="replacement_category_id">
                            <option value="0">Bitte auswählen…</option>
                            <?php foreach ($deleteCategoryOptions as $categoryOption): ?>
                                <option value="<?php echo (int) ($categoryOption['id'] ?? 0); ?>">
                                    <?php echo htmlspecialchars((string) ($categoryOption['option_label'] ?? $categoryOption['name'] ?? ''), ENT_QUOTES); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Beiträge mit dieser Kategorie werden vor dem Löschen auf die gewählte Ersatzkategorie umgestellt. Ist in den Kategorie-Einstellungen bereits eine Ersatzkategorie hinterlegt, wird diese automatisch verwendet.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger" id="deleteCategorySubmit" <?php echo $deleteCategorySubmitDisabled ? 'disabled' : ''; ?>>Löschen bestätigen</button>
                </div>
            </form>
        </div>
    </div>
</div>
