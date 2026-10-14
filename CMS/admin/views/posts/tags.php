<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$tags = $data['tags'] ?? [];
$tagOptions = $data['tagOptions'] ?? [];
$counts = $data['counts'] ?? [];
$editTag = $editTag ?? ($data['editTag'] ?? null);
$formValues = is_array($formValues ?? null) ? $formValues : [];
$formAlert = is_array($formAlert ?? null) ? $formAlert : null;
$editTagId = (int) ($formValues['tag_id'] ?? ($editTag['id'] ?? 0));
$editTagName = (string) ($formValues['tag_name'] ?? ($editTag['name'] ?? ''));
$editTagSlug = (string) ($formValues['tag_slug'] ?? ($editTag['slug'] ?? ''));
$editTagSlugEn = (string) ($formValues['tag_slug_en'] ?? ($editTag['slug_en'] ?? ''));
$editTagDescription = (string) ($formValues['tag_description'] ?? ($editTag['description'] ?? ''));
$isEditing = $editTagId > 0;
$deleteTagOptions = array_values(array_filter(
    $tagOptions,
    static fn(array $tagOption): bool => (int) ($tagOption['id'] ?? 0) > 0
));
$deleteTagSubmitDisabled = count($deleteTagOptions) <= 1;
$buildTagArchivePreviewPaths = static function (string $slug): array {
    $slug = trim((string) $slug, '/');
    if ($slug === '' || !function_exists('cms_get_archive_locales') || !function_exists('cms_get_archive_base')) {
        return [];
    }

    $paths = [];
    foreach (cms_get_archive_locales() as $locale) {
        $archiveBase = trim((string) cms_get_archive_base('tag', (string) $locale), '/');
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
$tagArchivePreviewPaths = $buildTagArchivePreviewPaths($editTagSlug);
$panelState = [
    'open' => $isEditing || !empty($formAlert),
    'mode' => $isEditing ? 'edit' : 'create',
    'successMessage' => (is_array($alert ?? null) && (($alert['type'] ?? '') === 'success')) ? (string) ($alert['message'] ?? '') : '',
    'formError' => is_array($formAlert) ? [
        'message' => (string) ($formAlert['message'] ?? 'Tag konnte nicht gespeichert werden.'),
        'details' => array_values(array_filter(array_map('strval', (array) ($formAlert['details'] ?? [])), static fn(string $detail): bool => trim($detail) !== '')),
    ] : null,
    'values' => [
        'tag_id' => $editTagId,
        'tag_name' => $editTagName,
        'tag_slug' => $editTagSlug,
        'tag_slug_en' => $editTagSlugEn,
        'tag_description' => $editTagDescription,
    ],
];
$panelStateJson = htmlspecialchars((string) json_encode($panelState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
?>

<div class="page-header d-print-none">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle">Seiten &amp; Beiträge</div>
                <h2 class="page-title mb-1">Beitrags-Tags</h2>
                <div class="content-listing-header__meta">
                    <span><?php echo (int) ($counts['total'] ?? 0); ?> Tags</span>
                    <span><?php echo (int) ($counts['assigned_posts'] ?? 0); ?> Tag-Zuweisungen</span>
                </div>
            </div>
            <button type="button" class="btn btn-primary d-print-none" data-taxonomy-open>
                <i class="ti ti-plus" style="font-size:15px;" aria-hidden="true"></i>
                Neues Tag anlegen
            </button>
        </div>
    </div>
</div>

<div class="page-body" data-taxonomy-panel-root data-taxonomy-list-url="/admin/post-tags">
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
                                <label class="form-label mb-0 small text-secondary" for="bulkTagReplacementSelect">Ersatztag für Auswahl</label>
                                <select class="form-select form-select-sm" id="bulkTagReplacementSelect" form="bulkTagForm" name="bulk_replacement_tag_id" aria-label="Gemeinsamer Ersatztag">
                                    <option value="0">Ohne Ersatztag</option>
                                    <?php foreach ($deleteTagOptions as $tagOption): ?>
                                        <option value="<?php echo (int) ($tagOption['id'] ?? 0); ?>">
                                            <?php echo htmlspecialchars((string) ($tagOption['name'] ?? ''), ENT_QUOTES); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="content-entity-toolbar__actions">
                                <button type="submit" form="bulkTagForm" class="btn btn-outline-danger btn-sm">Ausgewählte löschen</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card content-listing-card content-entity-list-card">
                      <form method="post" id="bulkTagForm"
                          data-confirm-title="Tags gesammelt löschen"
                          data-confirm-message="Ausgewählte Tags wirklich löschen? Tags mit Beitragsbezug benötigen einen gültigen Ersatztag."
                          data-confirm-text="Tags löschen"
                          data-confirm-class="btn-danger"
                          data-confirm-status-class="bg-danger"></form>
                    <div class="card-header">
                        <div>
                            <h3 class="card-title content-entity-card-title mb-0">Vorhandene Tags</h3>
                            <p class="content-entity-card-subtitle">Ausgewählte Tags können gesammelt gelöscht werden; bei Beitragsbezug ist ein Ersatztag Pflicht.</p>
                        </div>
                    </div>
                    <input type="hidden" form="bulkTagForm" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
                    <input type="hidden" form="bulkTagForm" name="action" value="bulk_delete_tags">
                    <div class="table-responsive">
                        <table class="table table-vcenter card-table content-listing-table content-entity-table">
                            <thead>
                                <tr>
                                    <th class="w-1">Auswahl</th>
                                    <th>Name</th>
                                    <th>Slug</th>
                                    <th>Beiträge</th>
                                    <th class="w-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($tags === []): ?>
                                    <tr><td colspan="5" class="text-secondary text-center py-4">Noch keine Tags vorhanden.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($tags as $tag): ?>
                                    <tr class="content-listing-table__row">
                                        <td>
                                            <input class="form-check-input" type="checkbox" form="bulkTagForm" name="tag_ids[]" value="<?php echo (int) ($tag['id'] ?? 0); ?>" aria-label="Tag auswählen: <?php echo htmlspecialchars((string) ($tag['name'] ?? ''), ENT_QUOTES); ?>">
                                        </td>
                                        <td class="fw-medium"><?php echo htmlspecialchars((string) ($tag['name'] ?? ''), ENT_QUOTES); ?></td>
                                        <td>
                                            <code><?php echo htmlspecialchars((string) ($tag['slug'] ?? ''), ENT_QUOTES); ?></code>
                                            <?php if (trim((string) ($tag['slug_en'] ?? '')) !== ''): ?>
                                                <div class="text-secondary small">EN: <code><?php echo htmlspecialchars((string) $tag['slug_en'], ENT_QUOTES); ?></code></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo (int) ($tag['post_count'] ?? 0); ?></td>
                                        <td class="table-actions content-listing-table__actions-cell">
                                            <div class="table-row-actions">
                                                <a href="<?php echo htmlspecialchars('/admin/post-tags?edit=' . (int) ($tag['id'] ?? 0), ENT_QUOTES); ?>" class="btn btn-outline-secondary btn-sm">Bearbeiten</a>
                                                <form method="post" class="js-delete-tag-form"
                                                      data-tag-id="<?php echo (int) ($tag['id'] ?? 0); ?>"
                                                      data-tag-name="<?php echo htmlspecialchars((string) ($tag['name'] ?? ''), ENT_QUOTES); ?>"
                                                      data-assigned-posts="<?php echo (int) ($tag['post_count'] ?? 0); ?>">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
                                                    <input type="hidden" name="action" value="delete_tag">
                                                    <input type="hidden" name="tag_id" value="<?php echo (int) ($tag['id'] ?? 0); ?>">
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
    <form method="post" action="/admin/post-tags" data-taxonomy-form novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
        <input type="hidden" name="action" value="save_tag">
        <input type="hidden" name="tag_id" value="<?php echo $editTagId; ?>" data-taxonomy-field="tag_id">
        <div class="taxonomy-slide-panel__header">
            <h3 class="card-title mb-0" data-taxonomy-title><?php echo $isEditing ? 'Tag bearbeiten' : 'Neues Tag anlegen'; ?></h3>
            <button type="button" class="btn btn-icon btn-ghost-secondary" data-taxonomy-close aria-label="Schließen">
                <i class="ti ti-x" style="font-size:18px;" aria-hidden="true"></i>
            </button>
        </div>
        <div class="taxonomy-slide-panel__body">
            <div class="alert alert-danger taxonomy-form-error d-none" data-taxonomy-form-error role="alert"></div>
            <div class="mb-3">
                <label class="form-label required" for="postTagPanelName">Name</label>
                <input type="text" class="form-control" id="postTagPanelName" name="tag_name" value="<?php echo htmlspecialchars($editTagName, ENT_QUOTES); ?>" data-taxonomy-field="tag_name" data-taxonomy-name required>
                <div class="taxonomy-validation-error d-none" data-taxonomy-error-for="tag_name"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="postTagPanelSlug">Slug</label>
                <div class="input-group">
                    <span class="input-group-text">/</span>
                    <input type="text" class="form-control" id="postTagPanelSlug" name="tag_slug" value="<?php echo htmlspecialchars($editTagSlug, ENT_QUOTES); ?>" placeholder="wird automatisch generiert" data-taxonomy-field="tag_slug" data-taxonomy-slug>
                </div>
                <div class="taxonomy-validation-error d-none" data-taxonomy-error-for="tag_slug"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="postTagPanelSlugEn">Slug (EN)</label>
                <div class="input-group">
                    <span class="input-group-text">/</span>
                    <input type="text" class="form-control" id="postTagPanelSlugEn" name="tag_slug_en" value="<?php echo htmlspecialchars($editTagSlugEn, ENT_QUOTES); ?>" placeholder="optional – für englische Archiv-URLs" data-taxonomy-field="tag_slug_en">
                </div>
                <div class="form-hint">Optionaler zweiter Slug für das englische Tag-Archiv. Leer lassen, um den Haupt-Slug für alle Sprachen zu nutzen.</div>
                <div class="taxonomy-validation-error d-none" data-taxonomy-error-for="tag_slug_en"></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="postTagPanelDescription">Beschreibung</label>
                <textarea class="form-control" id="postTagPanelDescription" rows="2" name="tag_description" maxlength="2000" data-taxonomy-field="tag_description"><?php echo htmlspecialchars($editTagDescription, ENT_QUOTES); ?></textarea>
            </div>
        </div>
        <div class="taxonomy-slide-panel__footer">
            <button type="button" class="btn btn-outline-secondary" data-taxonomy-cancel>Abbrechen</button>
            <button type="submit" class="btn btn-primary" data-taxonomy-submit>Anlegen</button>
        </div>
    </form>
    <div hidden data-taxonomy-panel-state="<?php echo $panelStateJson; ?>"></div>
</aside>

<div class="modal modal-blur fade" id="deleteTagModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" id="deleteTagModalForm">
                <div class="modal-header">
                    <h5 class="modal-title">Tag löschen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="delete_tag">
                    <input type="hidden" name="tag_id" id="deleteTagId" value="0">

                    <p class="mb-2">Der Tag <strong id="deleteTagName"></strong> wird gelöscht.</p>
                    <p class="text-secondary mb-0" id="deleteTagHint">Zugeordnete Beziehungen werden dabei entfernt.</p>
                    <p class="mt-3 mb-0 fw-medium d-none" id="deleteTagQuestion">In welchen Ersatztag sollen die zugeordneten Artikel verschoben werden?</p>

                    <div class="mt-3 d-none" id="deleteTagReassignWrap">
                        <label class="form-label" for="replacementTagId">Neuer Tag für betroffene Beiträge</label>
                        <select class="form-select" id="replacementTagId" name="replacement_tag_id">
                            <option value="0">Bitte auswählen…</option>
                            <?php foreach ($deleteTagOptions as $tagOption): ?>
                                <option value="<?php echo (int) ($tagOption['id'] ?? 0); ?>">
                                    <?php echo htmlspecialchars((string) ($tagOption['name'] ?? ''), ENT_QUOTES); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint">Beiträge mit diesem Tag werden vor dem Löschen auf den gewählten Ersatztag umgestellt.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-link link-secondary me-auto" data-bs-dismiss="modal">Abbrechen</button>
                    <button type="submit" class="btn btn-danger" id="deleteTagSubmit" <?php echo $deleteTagSubmitDisabled ? 'disabled' : ''; ?>>Löschen bestätigen</button>
                </div>
            </form>
        </div>
    </div>
</div>
