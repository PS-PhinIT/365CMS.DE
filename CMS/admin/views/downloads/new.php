<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – neuen Download anlegen (mit Datei, externem Link oder zunächst ohne Version).
 *
 * @var array<string, mixed> $dlData
 * @var string $csrfToken
 * @var callable $dlE
 * @var callable $dlT
 */

$dlCategories = is_array($dlData['categories'] ?? null) ? $dlData['categories'] : [];
?>
<form method="post" action="/admin/downloads?action=new" enctype="multipart/form-data" class="card">
    <input type="hidden" name="csrf_token" value="<?= $dlE($csrfToken) ?>">
    <input type="hidden" name="action" value="create">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-8">
                <label class="form-label" for="dlNewTitle"><?= $dlT('downloads.admin.field_title') ?></label>
                <input type="text" class="form-control" id="dlNewTitle" name="title" maxlength="255" aria-describedby="dlNewTitleHint">
                <div class="form-hint" id="dlNewTitleHint"><?= $dlT('downloads.admin.field_title_new_hint') ?></div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dlNewCategory"><?= $dlT('downloads.admin.col_category') ?></label>
                <select class="form-select" id="dlNewCategory" name="category_id">
                    <option value="0"><?= $dlT('downloads.admin.no_category') ?></option>
                    <?php foreach ($dlCategories as $dlCategory): ?>
                        <option value="<?= (int) $dlCategory['id'] ?>"><?= $dlE($dlCategory['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="dlNewSummary"><?= $dlT('downloads.admin.field_summary') ?></label>
                <textarea class="form-control" id="dlNewSummary" name="summary" rows="2" maxlength="1000"></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="dlNewFile"><?= $dlT('downloads.admin.field_file') ?></label>
                <input type="file" class="form-control" id="dlNewFile" name="file" aria-describedby="dlNewFileHint">
                <div class="form-hint" id="dlNewFileHint"><?= $dlT('downloads.admin.field_file_hint', implode(', ', (array) ($dlData['allowed_types'] ?? [])), (string) ($dlData['max_size'] ?? '')) ?></div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="dlNewExternal"><?= $dlT('downloads.admin.field_external') ?></label>
                <input type="url" class="form-control" id="dlNewExternal" name="external_url" maxlength="500" placeholder="https://" aria-describedby="dlNewExternalHint">
                <div class="form-hint" id="dlNewExternalHint"><?= $dlT('downloads.admin.field_external_hint') ?></div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dlNewVersion"><?= $dlT('downloads.admin.col_version') ?></label>
                <input type="text" class="form-control" id="dlNewVersion" name="version" maxlength="50" placeholder="1.0">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dlNewStatus"><?= $dlT('downloads.admin.col_status') ?></label>
                <select class="form-select" id="dlNewStatus" name="status">
                    <option value="published"><?= $dlT('downloads.status.published') ?></option>
                    <option value="draft"><?= $dlT('downloads.status.draft') ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dlNewAccess"><?= $dlT('downloads.admin.col_access') ?></label>
                <select class="form-select" id="dlNewAccess" name="access_level">
                    <?php foreach (['public', 'logged_in', 'member'] as $dlLevel): ?>
                        <option value="<?= $dlE($dlLevel) ?>"<?= ($dlData['default_access'] ?? 'public') === $dlLevel ? ' selected' : '' ?>><?= $dlT('downloads.access.' . $dlLevel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex gap-2">
        <span class="text-secondary small align-self-center"><?= $dlT('downloads.admin.new_hint') ?></span>
        <a class="btn ms-auto" href="/admin/downloads"><?= $dlT('downloads.admin.cancel') ?></a>
        <button type="submit" class="btn btn-primary"><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $dlT('downloads.admin.create') ?></button>
    </div>
</form>
