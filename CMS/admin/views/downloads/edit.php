<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Downloads – Bearbeiten in Abschnitten: Allgemein · Versionen · Eigenschaften · Zugriff · Statistik · Verwendung · SEO.
 * Jeder Abschnitt hat ein eigenes Formular und speichert nur seine Felder.
 *
 * @var array<string, mixed> $dlData
 * @var string $csrfToken
 * @var callable $dlE
 * @var callable $dlT
 * @var callable $dlDate
 * @var callable $dlNumber
 * @var array<string, string> $dlStatusBadge
 */

use CMS\Services\Downloads\DownloadSecurity;

$dlItem = is_array($dlData['item'] ?? null) ? $dlData['item'] : [];
$dlId = (int) ($dlItem['id'] ?? 0);
$dlSection = (string) ($dlData['section'] ?? 'general');
$dlCanManage = !empty($dlData['can_manage']);
$dlIsTrash = !empty($dlData['is_trash']);
$dlStatus = (string) ($dlItem['status'] ?? 'draft');
$dlSections = $dlCanManage
    ? ['general' => 'downloads.admin.section_general', 'versions' => 'downloads.admin.section_versions', 'properties' => 'downloads.admin.section_properties',
       'access' => 'downloads.admin.section_access', 'stats' => 'downloads.admin.section_stats', 'usage' => 'downloads.admin.section_usage', 'seo' => 'downloads.admin.section_seo']
    : ['stats' => 'downloads.admin.section_stats'];
if (empty($dlData['property_fields'])) {
    unset($dlSections['properties']);
}
$dlFormStart = static function (string $action, string $extra = '') use ($dlE, $csrfToken, $dlId, $dlSection): string {
    return '<form method="post" action="' . $dlE(DownloadsModule::url(['action' => 'edit', 'id' => $dlId, 'section' => $dlSection])) . '"' . $extra . '>'
        . '<input type="hidden" name="csrf_token" value="' . $dlE($csrfToken) . '">'
        . '<input type="hidden" name="action" value="' . $dlE($action) . '">'
        . '<input type="hidden" name="id" value="' . $dlId . '">';
};
$dlValue = static fn (string $key): string => is_scalar($dlItem[$key] ?? null) ? (string) $dlItem[$key] : '';
$dlChecked = static fn (bool $on): string => $on ? ' checked' : '';
$dlLinksText = implode("\n", array_map(static fn (array $link): string => $link['label'] . ' | ' . $link['url'], (array) ($dlItem['external_links'] ?? [])));
?>

<div class="card mb-4">
    <div class="card-body d-flex flex-wrap align-items-center gap-3">
        <span class="badge <?= $dlE($dlStatusBadge[$dlStatus] ?? 'bg-secondary-lt') ?>"><?= $dlT('downloads.status.' . $dlStatus) ?></span>
        <span class="text-secondary"><?= $dlT('downloads.admin.meta_line', $dlNumber((int) ($dlItem['download_count'] ?? 0)), (int) ($dlData['usage_count'] ?? 0)) ?></span>
        <?php if (!$dlIsTrash && $dlStatus !== 'archived'): ?>
            <a class="ms-auto" href="<?= $dlE((string) $dlData['public_url']) ?>" target="_blank" rel="noopener"><i class="ti ti-external-link me-1" aria-hidden="true"></i><?= $dlT('downloads.admin.view_public') ?></a>
            <code class="small"><?= $dlE((string) $dlData['file_url']) ?></code>
        <?php endif; ?>
        <?php if ($dlCanManage): ?>
            <?= $dlIsTrash
                ? $dlFormStart('restore', ' class="ms-auto d-inline"') . '<button type="submit" class="btn btn-sm">' . $dlT('downloads.admin.action_restore') . '</button></form>'
                    . $dlFormStart('delete', ' class="d-inline" data-confirm-title="' . $dlT('downloads.admin.delete_title') . '" data-confirm-message="' . $dlE(sprintf((string) __('downloads.admin.delete_confirm'), (string) $dlItem['title'], (int) ($dlData['usage_count'] ?? 0))) . '" data-confirm-text="' . $dlT('downloads.admin.action_delete') . '" data-confirm-class="btn-danger"')
                    . '<button type="submit" class="btn btn-sm btn-outline-danger">' . $dlT('downloads.admin.action_delete') . '</button></form>'
                : $dlFormStart('trash', ' class="d-inline" data-confirm-title="' . $dlT('downloads.admin.trash_title') . '" data-confirm-message="' . $dlE(sprintf((string) __('downloads.admin.trash_confirm'), (string) $dlItem['title'], (int) ($dlData['usage_count'] ?? 0))) . '" data-confirm-text="' . $dlT('downloads.admin.action_trash') . '" data-confirm-class="btn-danger"')
                    . '<button type="submit" class="btn btn-sm btn-outline-danger">' . $dlT('downloads.admin.action_trash') . '</button></form>' ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($dlIsTrash): ?>
    <div class="alert alert-warning" role="alert"><?= $dlT('downloads.admin.in_trash_notice') ?></div>
<?php endif; ?>

<?php if (count($dlSections) > 1): ?>
    <ul class="nav nav-tabs mb-4 cms-dl-sections">
        <?php foreach ($dlSections as $dlKey => $dlLabel): ?>
            <li class="nav-item">
                <a class="nav-link<?= $dlSection === $dlKey ? ' active' : '' ?>"<?= $dlSection === $dlKey ? ' aria-current="page"' : '' ?> href="<?= $dlE(DownloadsModule::url(['action' => 'edit', 'id' => $dlId, 'section' => $dlKey === 'general' ? null : $dlKey])) ?>">
                    <?= $dlT($dlLabel) ?>
                    <?php if ($dlKey === 'versions'): ?><span class="badge bg-secondary-lt ms-1"><?= count((array) ($dlItem['versions'] ?? [])) ?></span><?php endif; ?>
                    <?php if ($dlKey === 'usage'): ?><span class="badge bg-secondary-lt ms-1"><?= (int) ($dlData['usage_count'] ?? 0) ?></span><?php endif; ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($dlSection === 'general'): ?>
    <?= $dlFormStart('save', ' class="card"') ?>
        <input type="hidden" name="section" value="general">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label required" for="dlTitle"><?= $dlT('downloads.admin.field_title') ?></label>
                    <input type="text" class="form-control" id="dlTitle" name="title" maxlength="255" required value="<?= $dlE($dlValue('title')) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dlSlug"><?= $dlT('downloads.admin.field_slug') ?></label>
                    <input type="text" class="form-control" id="dlSlug" name="slug" maxlength="190" pattern="<?= $dlE(DownloadSecurity::SLUG_PATTERN) ?>" value="<?= $dlE($dlValue('slug')) ?>" aria-describedby="dlSlugHint">
                    <div class="form-hint" id="dlSlugHint"><?= $dlT('downloads.admin.field_slug_hint') ?></div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dlStatus"><?= $dlT('downloads.admin.col_status') ?></label>
                    <select class="form-select" id="dlStatus" name="status">
                        <?php foreach (['published', 'draft', 'archived'] as $dlOption): ?>
                            <option value="<?= $dlE($dlOption) ?>"<?= $dlStatus === $dlOption ? ' selected' : '' ?>><?= $dlT('downloads.status.' . $dlOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dlCategory"><?= $dlT('downloads.admin.col_category') ?></label>
                    <select class="form-select" id="dlCategory" name="category_id">
                        <option value="0"><?= $dlT('downloads.admin.no_category') ?></option>
                        <?php foreach ((array) ($dlData['categories'] ?? []) as $dlCategory): ?>
                            <option value="<?= (int) $dlCategory['id'] ?>"<?= (int) ($dlItem['category_id'] ?? 0) === (int) $dlCategory['id'] ? ' selected' : '' ?>><?= $dlE($dlCategory['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="dlTags"><?= $dlT('downloads.admin.field_tags') ?></label>
                    <input type="text" class="form-control" id="dlTags" name="tags" maxlength="1000" value="<?= $dlE(implode(', ', array_column((array) ($dlItem['tags'] ?? []), 'name'))) ?>" aria-describedby="dlTagsHint">
                    <div class="form-hint" id="dlTagsHint"><?= $dlT('downloads.admin.field_tags_hint') ?></div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="dlSummary"><?= $dlT('downloads.admin.field_summary') ?></label>
                    <textarea class="form-control" id="dlSummary" name="summary" rows="2" maxlength="1000"><?= $dlE($dlValue('summary')) ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label" for="dlDescription"><?= $dlT('downloads.admin.field_description') ?></label>
                    <textarea class="form-control" id="dlDescription" name="description" rows="8" aria-describedby="dlDescriptionHint"><?= $dlE($dlValue('description')) ?></textarea>
                    <div class="form-hint" id="dlDescriptionHint"><?= $dlT('downloads.admin.field_description_hint') ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dlCover"><?= $dlT('downloads.admin.field_cover') ?></label>
                    <input type="text" class="form-control" id="dlCover" name="cover_media" maxlength="500" placeholder="/uploads/…" value="<?= $dlE($dlValue('cover_media')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dlAuthor"><?= $dlT('downloads.admin.field_author') ?></label>
                    <input type="text" class="form-control" id="dlAuthor" name="author_name" maxlength="190" value="<?= $dlE($dlValue('author_name')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dlLicense"><?= $dlT('downloads.field.license') ?></label>
                    <input type="text" class="form-control" id="dlLicense" name="license" maxlength="190" value="<?= $dlE($dlValue('license')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dlRequirements"><?= $dlT('downloads.field.requirements') ?></label>
                    <input type="text" class="form-control" id="dlRequirements" name="requirements" maxlength="500" placeholder="PowerShell 7.4+" value="<?= $dlE($dlValue('requirements')) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="dlLinks"><?= $dlT('downloads.admin.field_links') ?></label>
                    <textarea class="form-control" id="dlLinks" name="external_links" rows="3" aria-describedby="dlLinksHint"><?= $dlE($dlLinksText) ?></textarea>
                    <div class="form-hint" id="dlLinksHint"><?= $dlT('downloads.admin.field_links_hint') ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_signed" value="1"<?= $dlChecked(!empty($dlItem['is_signed'])) ?>>
                        <span class="form-check-label"><?= $dlT('downloads.admin.field_signed') ?></span>
                    </label>
                    <label class="form-label mt-2" for="dlSigner"><?= $dlT('downloads.admin.field_signer') ?></label>
                    <input type="text" class="form-control" id="dlSigner" name="signature_info" maxlength="255" value="<?= $dlE($dlValue('signature_info')) ?>">
                    <label class="form-check mt-3">
                        <input class="form-check-input" type="checkbox" name="is_featured" value="1"<?= $dlChecked(!empty($dlItem['is_featured'])) ?>>
                        <span class="form-check-label"><?= $dlT('downloads.admin.field_featured') ?></span>
                    </label>
                    <label class="form-label mt-2" for="dlSort"><?= $dlT('downloads.admin.field_sort') ?></label>
                    <input type="number" class="form-control w-auto" id="dlSort" name="sort_order" min="-100000" max="100000" step="1" value="<?= (int) ($dlItem['sort_order'] ?? 0) ?>">
                </div>
            </div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary"<?= $dlIsTrash ? ' disabled' : '' ?>><?= $dlT('downloads.admin.save') ?></button></div>
    </form>

<?php elseif ($dlSection === 'versions'): ?>
    <div class="card mb-4">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr>
                    <th><?= $dlT('downloads.admin.col_version') ?></th>
                    <th><?= $dlT('downloads.admin.col_file') ?></th>
                    <th><?= $dlT('downloads.admin.col_release') ?></th>
                    <th class="text-end"><?= $dlT('downloads.admin.col_downloads') ?></th>
                    <th class="w-1"><span class="visually-hidden"><?= $dlT('downloads.admin.col_actions') ?></span></th>
                </tr></thead>
                <tbody>
                <?php if ((array) ($dlItem['versions'] ?? []) === []): ?>
                    <tr><td colspan="5" class="text-secondary"><?= $dlT('downloads.admin.hint_no_version') ?></td></tr>
                <?php endif; ?>
                <?php foreach ((array) ($dlItem['versions'] ?? []) as $dlVersion): ?>
                    <?php $dlVid = (int) $dlVersion['id']; ?>
                    <tr class="<?= !empty($dlVersion['is_current']) ? 'cms-dl-version--current' : '' ?>">
                        <td class="text-nowrap">
                            <strong><?= $dlE($dlVersion['version']) ?></strong>
                            <?php if (!empty($dlVersion['is_current'])): ?><span class="badge bg-green-lt ms-1"><?= $dlT('downloads.single.current') ?></span><?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($dlVersion['is_external'])): ?>
                                <i class="ti ti-link me-1" aria-hidden="true"></i><span class="text-break"><?= $dlE($dlVersion['external_url']) ?></span>
                            <?php else: ?>
                                <?= $dlE($dlVersion['file_name']) ?> <span class="text-secondary small">· <?= $dlE(DownloadSecurity::formatBytes((int) $dlVersion['file_size'])) ?></span>
                                <?php if ((string) $dlVersion['sha256'] !== ''): ?><div class="cms-dl-hash text-secondary">SHA-256 <code><?= $dlE($dlVersion['sha256']) ?></code></div><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?= $dlE($dlDate((string) $dlVersion['release_date'], false)) ?></td>
                        <td class="text-end"><?= $dlNumber((int) $dlVersion['download_count']) ?></td>
                        <td class="text-nowrap">
                            <?php if (!$dlIsTrash): ?>
                                <details class="d-inline-block">
                                    <summary class="btn btn-sm"><?= $dlT('downloads.admin.action_edit') ?></summary>
                                    <?= $dlFormStart('update_version', ' class="card card-body mt-2"') ?>
                                        <input type="hidden" name="version_id" value="<?= $dlVid ?>">
                                        <label class="form-label" for="dlV<?= $dlVid ?>Number"><?= $dlT('downloads.admin.col_version') ?></label>
                                        <input type="text" class="form-control mb-2" id="dlV<?= $dlVid ?>Number" name="version" maxlength="50" required value="<?= $dlE($dlVersion['version']) ?>">
                                        <label class="form-label" for="dlV<?= $dlVid ?>Date"><?= $dlT('downloads.admin.col_release') ?></label>
                                        <input type="date" class="form-control mb-2" id="dlV<?= $dlVid ?>Date" name="release_date" value="<?= $dlE($dlVersion['release_date']) ?>">
                                        <?php if (!empty($dlVersion['is_external'])): ?>
                                            <label class="form-label" for="dlV<?= $dlVid ?>Url"><?= $dlT('downloads.admin.field_external') ?></label>
                                            <input type="url" class="form-control mb-2" id="dlV<?= $dlVid ?>Url" name="external_url" maxlength="500" value="<?= $dlE($dlVersion['external_url']) ?>">
                                        <?php endif; ?>
                                        <label class="form-label" for="dlV<?= $dlVid ?>Log"><?= $dlT('downloads.field.changelog') ?></label>
                                        <textarea class="form-control mb-2" id="dlV<?= $dlVid ?>Log" name="changelog" rows="4"><?= $dlE($dlVersion['changelog']) ?></textarea>
                                        <button type="submit" class="btn btn-sm btn-primary"><?= $dlT('downloads.admin.save') ?></button>
                                    </form>
                                </details>
                                <?php if (empty($dlVersion['is_current'])): ?>
                                    <?= $dlFormStart('set_current', ' class="d-inline"') ?><input type="hidden" name="version_id" value="<?= $dlVid ?>"><button type="submit" class="btn btn-sm"><?= $dlT('downloads.admin.action_make_current') ?></button></form>
                                    <?= $dlFormStart('delete_version', ' class="d-inline" data-confirm-title="' . $dlT('downloads.admin.version_delete_title') . '" data-confirm-message="' . $dlE(sprintf((string) __('downloads.admin.version_delete_confirm'), (string) $dlVersion['version'])) . '" data-confirm-text="' . $dlT('downloads.admin.action_delete') . '" data-confirm-class="btn-danger"') ?>
                                        <input type="hidden" name="version_id" value="<?= $dlVid ?>"><button type="submit" class="btn btn-sm btn-outline-danger"><?= $dlT('downloads.admin.action_delete') ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if (trim((string) $dlVersion['changelog']) !== ''): ?>
                        <tr><td></td><td colspan="4" class="text-secondary small border-top-0 pt-0"><?= nl2br($dlE($dlVersion['changelog'])) ?></td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (!$dlIsTrash): ?>
        <?= $dlFormStart('add_version', ' class="card" enctype="multipart/form-data"') ?>
            <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.add_version') ?></h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label required" for="dlNewVer"><?= $dlT('downloads.admin.col_version') ?></label>
                        <input type="text" class="form-control" id="dlNewVer" name="version" maxlength="50" required pattern="<?= $dlE(DownloadSecurity::VERSION_PATTERN) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="dlNewVerDate"><?= $dlT('downloads.admin.col_release') ?></label>
                        <input type="date" class="form-control" id="dlNewVerDate" name="release_date" value="<?= $dlE(date('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <label class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="make_current" value="1" checked>
                            <span class="form-check-label"><?= $dlT('downloads.admin.make_current') ?></span>
                        </label>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dlNewVerFile"><?= $dlT('downloads.admin.field_file') ?></label>
                        <input type="file" class="form-control" id="dlNewVerFile" name="file" aria-describedby="dlNewVerFileHint">
                        <div class="form-hint" id="dlNewVerFileHint"><?= $dlT('downloads.admin.field_file_hint', implode(', ', (array) ($dlData['allowed_types'] ?? [])), (string) ($dlData['max_size'] ?? '')) ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dlNewVerUrl"><?= $dlT('downloads.admin.field_external') ?></label>
                        <input type="url" class="form-control" id="dlNewVerUrl" name="external_url" maxlength="500" placeholder="https://">
                        <div class="form-hint"><?= $dlT('downloads.admin.field_external_version_hint') ?></div>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="dlNewVerLog"><?= $dlT('downloads.field.changelog') ?></label>
                        <textarea class="form-control" id="dlNewVerLog" name="changelog" rows="4"></textarea>
                    </div>
                </div>
            </div>
            <div class="card-footer text-end"><button type="submit" class="btn btn-primary"><i class="ti ti-upload me-1" aria-hidden="true"></i><?= $dlT('downloads.admin.add_version') ?></button></div>
        </form>
    <?php endif; ?>

<?php elseif ($dlSection === 'properties'): ?>
    <?= $dlFormStart('save', ' class="card"') ?>
        <input type="hidden" name="section" value="properties">
        <div class="card-body">
            <div class="row g-3">
                <?php foreach ((array) ($dlData['property_fields'] ?? []) as $dlField): ?>
                    <?php $dlKey = (string) $dlField['key']; $dlFieldId = 'dlProp_' . $dlKey; $dlCurrent = (string) (($dlItem['properties'] ?? [])[$dlKey] ?? ''); ?>
                    <div class="col-md-6">
                        <?php if ($dlField['type'] === 'boolean'): ?>
                            <input type="hidden" name="properties[<?= $dlE($dlKey) ?>]" value="0">
                            <label class="form-check mt-4">
                                <input class="form-check-input" type="checkbox" name="properties[<?= $dlE($dlKey) ?>]" value="1"<?= $dlChecked($dlCurrent === '1') ?>>
                                <span class="form-check-label"><?= $dlE($dlField['label']) ?></span>
                            </label>
                        <?php else: ?>
                            <label class="form-label" for="<?= $dlE($dlFieldId) ?>"><?= $dlE($dlField['label']) ?></label>
                            <?php if ($dlField['type'] === 'textarea'): ?>
                                <textarea class="form-control" id="<?= $dlE($dlFieldId) ?>" name="properties[<?= $dlE($dlKey) ?>]" rows="3" maxlength="5000"><?= $dlE($dlCurrent) ?></textarea>
                            <?php elseif ($dlField['type'] === 'select'): ?>
                                <select class="form-select" id="<?= $dlE($dlFieldId) ?>" name="properties[<?= $dlE($dlKey) ?>]">
                                    <option value="">–</option>
                                    <?php foreach ((array) $dlField['options'] as $dlOption): ?>
                                        <option value="<?= $dlE($dlOption) ?>"<?= $dlCurrent === (string) $dlOption ? ' selected' : '' ?>><?= $dlE($dlOption) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input class="form-control" id="<?= $dlE($dlFieldId) ?>" name="properties[<?= $dlE($dlKey) ?>]" value="<?= $dlE($dlCurrent) ?>"
                                       type="<?= $dlE(['number' => 'text', 'url' => 'url', 'date' => 'date'][$dlField['type']] ?? 'text') ?>"<?= $dlField['type'] === 'number' ? ' inputmode="decimal"' : '' ?> maxlength="500">
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-footer d-flex">
            <a class="text-secondary small align-self-center" href="/admin/downloads?tab=settings"><?= $dlT('downloads.admin.properties_manage') ?></a>
            <button type="submit" class="btn btn-primary ms-auto"<?= $dlIsTrash ? ' disabled' : '' ?>><?= $dlT('downloads.admin.save') ?></button>
        </div>
    </form>

<?php elseif ($dlSection === 'access'): ?>
    <?= $dlFormStart('save', ' class="card"') ?>
        <input type="hidden" name="section" value="access">
        <div class="card-body">
            <fieldset class="mb-3">
                <legend class="form-label"><?= $dlT('downloads.admin.col_access') ?></legend>
                <?php foreach (\CMS\Services\Downloads\DownloadSettings::ACCESS_LEVELS as $dlLevel): ?>
                    <label class="form-check">
                        <input class="form-check-input" type="radio" name="access_level" value="<?= $dlE($dlLevel) ?>"<?= $dlChecked((string) ($dlItem['access_level'] ?? 'public') === $dlLevel) ?>>
                        <span class="form-check-label"><?= $dlT('downloads.access.' . $dlLevel) ?> <span class="text-secondary small">– <?= $dlT('downloads.admin.access_hint_' . $dlLevel) ?></span></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <div class="row g-3">
                <fieldset class="col-md-6">
                    <legend class="form-label"><?= $dlT('downloads.admin.access_roles') ?></legend>
                    <?php foreach ((array) ($dlData['roles'] ?? []) as $dlRole => $dlRoleLabel): ?>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="access_roles[]" value="<?= $dlE($dlRole) ?>"<?= $dlChecked(in_array((string) $dlRole, (array) ($dlItem['access_roles'] ?? []), true)) ?>>
                            <span class="form-check-label"><?= $dlE($dlRoleLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <div class="form-hint"><?= $dlT('downloads.admin.access_roles_hint') ?></div>
                </fieldset>
                <fieldset class="col-md-6">
                    <legend class="form-label"><?= $dlT('downloads.admin.access_plans') ?></legend>
                    <?php if ((array) ($dlData['plans'] ?? []) === []): ?>
                        <p class="text-secondary small"><?= $dlT('downloads.admin.access_no_plans') ?></p>
                    <?php endif; ?>
                    <?php foreach ((array) ($dlData['plans'] ?? []) as $dlPlan => $dlPlanLabel): ?>
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="access_plans[]" value="<?= (int) $dlPlan ?>"<?= $dlChecked(in_array((int) $dlPlan, (array) ($dlItem['access_plans'] ?? []), true)) ?>>
                            <span class="form-check-label"><?= $dlE($dlPlanLabel) ?></span>
                        </label>
                    <?php endforeach; ?>
                    <div class="form-hint"><?= $dlT('downloads.admin.access_plans_hint') ?></div>
                </fieldset>
            </div>
            <p class="text-secondary small mt-3 mb-0"><?= $dlT('downloads.admin.access_note') ?></p>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary"<?= $dlIsTrash ? ' disabled' : '' ?>><?= $dlT('downloads.admin.save') ?></button></div>
    </form>

<?php elseif ($dlSection === 'stats'): ?>
    <?php $dlPeriod = (int) ($dlData['period'] ?? 30); $dlSummary = (array) ($dlData['summary'] ?? []); $dlSeries = (array) ($dlData['series'] ?? []); ?>
    <div class="card mb-4">
        <div class="card-header">
            <h3 class="card-title"><?= $dlT('downloads.admin.stats_period', $dlPeriod) ?></h3>
            <div class="card-actions btn-group">
                <?php foreach (\CMS\Services\Downloads\DownloadStatsService::PERIODS as $dlP): ?>
                    <a class="btn btn-sm<?= $dlP === $dlPeriod ? ' active' : '' ?>"<?= $dlP === $dlPeriod ? ' aria-current="true"' : '' ?> href="<?= $dlE(DownloadsModule::url(['action' => 'edit', 'id' => $dlId, 'section' => 'stats', 'period' => $dlP])) ?>"><?= $dlT('downloads.admin.days', $dlP) ?></a>
                <?php endforeach; ?>
                <a class="btn btn-sm" href="<?= $dlE(DownloadsModule::url(['export' => 'csv', 'id' => $dlId, 'period' => $dlPeriod])) ?>"><i class="ti ti-download me-1" aria-hidden="true"></i>CSV</a>
            </div>
        </div>
        <div class="card-body">
            <div class="cms-dl-kpis mb-3">
                <div><div class="text-secondary small"><?= $dlT('downloads.admin.kpi_period') ?></div><div class="cms-dl-kpi__value"><?= $dlNumber((int) ($dlSummary['period_hits'] ?? 0)) ?></div></div>
                <div><div class="text-secondary small"><?= $dlT('downloads.admin.kpi_total') ?></div><div class="cms-dl-kpi__value"><?= $dlNumber((int) ($dlSummary['total_hits'] ?? 0)) ?></div></div>
            </div>
            <?php require __DIR__ . '/chart.php'; ?>
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header"><h3 class="card-title"><?= $dlT('downloads.admin.by_version') ?></h3></div>
                <table class="table card-table">
                    <thead><tr><th><?= $dlT('downloads.admin.col_version') ?></th><th class="text-end"><?= $dlT('downloads.admin.kpi_period') ?></th><th class="text-end"><?= $dlT('downloads.admin.kpi_total') ?></th></tr></thead>
                    <tbody>
                    <?php foreach ((array) ($dlData['by_version'] ?? []) as $dlRow): ?>
                        <tr><td><?= $dlE($dlRow['version']) ?><?= $dlRow['is_current'] ? ' <span class="badge bg-green-lt">' . $dlT('downloads.single.current') . '</span>' : '' ?></td><td class="text-end"><?= $dlNumber($dlRow['period_hits']) ?></td><td class="text-end"><?= $dlNumber($dlRow['total_hits']) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col-lg-6">
            <?php $dlSources = (array) ($dlData['sources'] ?? []); require __DIR__ . '/sources.php'; ?>
        </div>
    </div>

<?php elseif ($dlSection === 'usage'): ?>
    <div class="card">
        <div class="card-body">
            <p class="text-secondary mb-0"><?= $dlT('downloads.admin.usage_intro') ?></p>
        </div>
        <?php if ((array) ($dlData['usages'] ?? []) === []): ?>
            <div class="card-body border-top text-secondary"><?= $dlT('downloads.admin.usage_empty') ?></div>
        <?php else: ?>
            <table class="table table-vcenter card-table">
                <thead><tr><th><?= $dlT('downloads.admin.col_title') ?></th><th><?= $dlT('downloads.admin.col_type') ?></th><th><?= $dlT('downloads.admin.col_status') ?></th></tr></thead>
                <tbody>
                <?php foreach ((array) $dlData['usages'] as $dlUsage): ?>
                    <tr>
                        <td><a href="<?= $dlE($dlUsage['edit_url']) ?>"><?= $dlE($dlUsage['title'] !== '' ? $dlUsage['title'] : '#' . $dlUsage['content_id']) ?></a></td>
                        <td><?= $dlT($dlUsage['content_type'] === 'page' ? 'calendar.type.page' : 'calendar.type.post') ?></td>
                        <td><?= $dlE($dlUsage['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

<?php elseif ($dlSection === 'seo'): ?>
    <?= $dlFormStart('save', ' class="card"') ?>
        <input type="hidden" name="section" value="seo">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="dlSeoTitle"><?= $dlT('downloads.admin.seo_title') ?></label>
                <input type="text" class="form-control" id="dlSeoTitle" name="seo_title" maxlength="255" value="<?= $dlE($dlValue('seo_title')) ?>" placeholder="<?= $dlE($dlValue('title')) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="dlSeoDesc"><?= $dlT('downloads.admin.seo_description') ?></label>
                <textarea class="form-control" id="dlSeoDesc" name="seo_description" rows="3" maxlength="500" placeholder="<?= $dlE($dlValue('summary')) ?>"><?= $dlE($dlValue('seo_description')) ?></textarea>
            </div>
            <label class="form-check">
                <input class="form-check-input" type="checkbox" name="seo_noindex" value="1"<?= $dlChecked(!empty($dlItem['seo_noindex'])) ?>>
                <span class="form-check-label"><?= $dlT('downloads.admin.seo_noindex') ?></span>
            </label>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary"<?= $dlIsTrash ? ' disabled' : '' ?>><?= $dlT('downloads.admin.save') ?></button></div>
    </form>
<?php endif; ?>
