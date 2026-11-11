<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Einstellungen: Darstellung, Upload, Zugriff, Zählung und Statistik, Archivseite, Eigenschaftsfelder.
 *
 * @var array<string, mixed> $dlData
 * @var string $csrfToken
 * @var callable $dlE
 * @var callable $dlT
 */

$dlS = (array) ($dlData['settings'] ?? []);
$dlV = static fn (string $key): string => is_scalar($dlS[$key] ?? null) ? (string) $dlS[$key] : '';
$dlC = static fn (string $key): string => !empty($dlS[$key]) ? ' checked' : '';
$dlAllowed = (array) ($dlS['allowed_types'] ?? []);
$dlSwitch = static function (string $key, string $labelKey) use ($dlE, $dlT, $dlC): string {
    return '<label class="form-check form-switch"><input class="form-check-input" type="checkbox" name="settings[' . $dlE($key) . ']" value="1"' . $dlC($key) . '><span class="form-check-label">' . $dlT($labelKey) . '</span></label>';
};
$dlNum = static function (string $key, string $labelKey, int $min, int $max, string $unitKey = '') use ($dlE, $dlT, $dlV): string {
    $id = 'dlSet_' . $key;

    return '<label class="form-label" for="' . $id . '">' . $dlT($labelKey) . '</label><div class="input-group">'
        . '<input type="number" class="form-control" id="' . $id . '" name="settings[' . $dlE($key) . ']" min="' . $min . '" max="' . $max . '" step="1" value="' . $dlE($dlV($key)) . '">'
        . ($unitKey !== '' ? '<span class="input-group-text">' . $dlT($unitKey) . '</span>' : '') . '</div>';
};
$dlText = static function (string $key, string $labelKey, int $max = 190) use ($dlE, $dlT, $dlV): string {
    $id = 'dlSet_' . $key;

    return '<label class="form-label" for="' . $id . '">' . $dlT($labelKey) . '</label><input type="text" class="form-control" id="' . $id . '" name="settings[' . $dlE($key) . ']" maxlength="' . $max . '" value="' . $dlE($dlV($key)) . '">';
};
?>
<form method="post" action="/admin/downloads?tab=settings">
    <input type="hidden" name="csrf_token" value="<?= $dlE($csrfToken) ?>">
    <input type="hidden" name="action" value="save_settings">

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.set_display') ?></h3></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label" for="dlSetTemplate"><?= $dlT('downloads.admin.set_template') ?></label>
                <select class="form-select" id="dlSetTemplate" name="settings[default_template]">
                    <?php foreach (\CMS\Services\Downloads\DownloadSettings::TEMPLATES as $dlTpl): ?>
                        <option value="<?= $dlE($dlTpl) ?>"<?= $dlV('default_template') === $dlTpl ? ' selected' : '' ?>><?= $dlT('downloads.template.' . $dlTpl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4"><?= $dlNum('per_page', 'downloads.admin.set_per_page', 6, 120) ?></div>
            <div class="col-md-4 pt-md-4"><?= $dlSwitch('show_search', 'downloads.admin.set_show_search') ?><?= $dlSwitch('show_category_overview', 'downloads.admin.set_show_categories') ?></div>
            <div class="col-md-6"><?= $dlText('archive_title', 'downloads.admin.set_archive_title') ?></div>
            <div class="col-md-6"><?= $dlText('archive_title_en', 'downloads.admin.set_archive_title_en') ?></div>
            <div class="col-md-6"><label class="form-label" for="dlSetDesc"><?= $dlT('downloads.admin.set_archive_description') ?></label><textarea class="form-control" id="dlSetDesc" name="settings[archive_description]" rows="2" maxlength="2000"><?= $dlE($dlV('archive_description')) ?></textarea></div>
            <div class="col-md-6"><label class="form-label" for="dlSetDescEn"><?= $dlT('downloads.admin.set_archive_description_en') ?></label><textarea class="form-control" id="dlSetDescEn" name="settings[archive_description_en]" rows="2" maxlength="2000"><?= $dlE($dlV('archive_description_en')) ?></textarea></div>
            <div class="col-md-4 pt-md-4"><?= $dlSwitch('show_nav_link', 'downloads.admin.set_nav_link') ?></div>
            <div class="col-md-4"><?= $dlText('nav_label', 'downloads.admin.set_nav_label', 60) ?></div>
            <div class="col-md-4"><?= $dlText('nav_label_en', 'downloads.admin.set_nav_label_en', 60) ?></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.set_upload') ?></h3></div>
        <div class="card-body row g-3">
            <div class="col-md-4"><?= $dlNum('max_size_mb', 'downloads.admin.set_max_size', 1, 4096, 'downloads.admin.unit_mb') ?>
                <?php if ((string) ($dlData['server_limit'] ?? '') !== ''): ?><div class="form-hint"><?= $dlT('downloads.admin.set_server_limit', (string) $dlData['server_limit']) ?></div><?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dlSetUploadStatus"><?= $dlT('downloads.admin.set_upload_status') ?></label>
                <select class="form-select" id="dlSetUploadStatus" name="settings[upload_status]">
                    <option value="published"<?= $dlV('upload_status') === 'published' ? ' selected' : '' ?>><?= $dlT('downloads.status.published') ?></option>
                    <option value="draft"<?= $dlV('upload_status') === 'draft' ? ' selected' : '' ?>><?= $dlT('downloads.status.draft') ?></option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="dlSetArea"><?= $dlT('downloads.admin.set_storage') ?></label>
                <input type="text" class="form-control" id="dlSetArea" name="settings[storage_area]" maxlength="40" pattern="[a-z0-9][a-z0-9_\-]{0,39}" value="<?= $dlE($dlV('storage_area')) ?>" aria-describedby="dlSetAreaHint">
                <div class="form-hint" id="dlSetAreaHint"><?= $dlT(!empty($dlData['private_outside_webroot']) ? 'downloads.admin.set_storage_hint_ok' : 'downloads.admin.set_storage_hint_webroot') ?></div>
            </div>
            <fieldset class="col-12">
                <legend class="form-label"><?= $dlT('downloads.admin.set_types') ?></legend>
                <?php foreach ((array) ($dlData['file_type_groups'] ?? []) as $dlGroup => $dlExts): ?>
                    <div class="mb-2"><span class="text-secondary small me-2"><?= $dlT('downloads.group.' . $dlGroup) ?>:</span>
                        <?php foreach ($dlExts as $dlExt): ?>
                            <label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="settings[allowed_types][]" value="<?= $dlE($dlExt) ?>"<?= in_array($dlExt, $dlAllowed, true) ? ' checked' : '' ?>><span class="form-check-label"><?= $dlE($dlExt) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </fieldset>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.set_access') ?></h3></div>
        <div class="card-body row g-3">
            <div class="col-md-4">
                <label class="form-label" for="dlSetAccess"><?= $dlT('downloads.admin.set_default_access') ?></label>
                <select class="form-select" id="dlSetAccess" name="settings[default_access]">
                    <?php foreach (['public', 'logged_in', 'member'] as $dlLevel): ?>
                        <option value="<?= $dlE($dlLevel) ?>"<?= $dlV('default_access') === $dlLevel ? ' selected' : '' ?>><?= $dlT('downloads.access.' . $dlLevel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 pt-md-4"><?= $dlSwitch('show_external_notice', 'downloads.admin.set_external_notice') ?></div>
            <div class="col-12">
                <label class="form-label" for="dlSetDomains"><?= $dlT('downloads.admin.set_domains') ?></label>
                <textarea class="form-control" id="dlSetDomains" name="settings[external_allowed_domains]" rows="3" aria-describedby="dlSetDomainsHint"><?= $dlE($dlV('external_allowed_domains')) ?></textarea>
                <div class="form-hint" id="dlSetDomainsHint"><?= $dlT('downloads.admin.set_domains_hint') ?></div>
            </div>
            <div class="col-md-4 pt-md-4"><?= $dlSwitch('rate_limit_enabled', 'downloads.admin.set_rate_limit') ?></div>
            <div class="col-md-4"><?= $dlNum('rate_limit_max', 'downloads.admin.set_rate_max', 5, 500) ?></div>
            <div class="col-md-4"><?= $dlNum('rate_limit_window', 'downloads.admin.set_rate_window', 30, 3600, 'downloads.admin.unit_seconds') ?></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.set_counting') ?></h3></div>
        <div class="card-body row g-3">
            <div class="col-md-4"><?= $dlNum('dedupe_window_minutes', 'downloads.admin.set_dedupe', 0, 1440, 'downloads.admin.unit_minutes') ?></div>
            <div class="col-md-4"><?= $dlNum('stats_retention_days', 'downloads.admin.set_retention', 7, 3650, 'downloads.admin.unit_days') ?></div>
            <div class="col-md-4 pt-md-4"><?= $dlSwitch('bot_filter', 'downloads.admin.set_bot_filter') ?><?= $dlSwitch('count_staff', 'downloads.admin.set_count_staff') ?></div>
            <div class="col-12"><div class="form-hint"><?= $dlT('downloads.admin.set_counting_hint') ?></div></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.set_properties') ?></h3></div>
        <div class="card-body">
            <label class="form-label" for="dlSetProps"><?= $dlT('downloads.admin.set_properties_label') ?></label>
            <textarea class="form-control font-monospace" id="dlSetProps" name="property_lines" rows="6" spellcheck="false" aria-describedby="dlSetPropsHint"><?= $dlE((string) ($dlData['property_lines'] ?? '')) ?></textarea>
            <div class="form-hint" id="dlSetPropsHint"><?= $dlT('downloads.admin.set_properties_hint') ?></div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary"><?= $dlT('downloads.admin.save') ?></button></div>
    </div>
</form>

<form method="post" action="/admin/downloads?tab=settings" class="card">
    <input type="hidden" name="csrf_token" value="<?= $dlE($csrfToken) ?>">
    <input type="hidden" name="action" value="rebuild_usage">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <div><strong><?= $dlT('downloads.admin.usage_rebuild') ?></strong><div class="text-secondary small"><?= $dlT('downloads.admin.usage_rebuild_hint') ?></div></div>
        <button type="submit" class="btn ms-auto"><?= $dlT('downloads.admin.usage_rebuild') ?></button>
    </div>
</form>
