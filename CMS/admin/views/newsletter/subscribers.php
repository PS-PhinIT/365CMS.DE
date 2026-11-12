<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Newsletter – Abonnenten: Filter, Liste mit Sammelaktionen, CSV-Export, Import, DSGVO-Exporte.
 *
 * @var array<string, mixed> $nlData
 * @var callable $nlE
 * @var callable $nlT
 * @var callable $nlDate
 * @var callable $nlNumber
 * @var string $nlCsrf
 * @var array<string, string> $nlStatusBadge
 */

$nlF = (array) ($nlData['filters'] ?? []);
$nlRows = (array) ($nlData['subscribers'] ?? []);
$nlPage = (int) ($nlData['page'] ?? 1);
$nlPages = (int) ($nlData['pages'] ?? 1);
$nlSegments = (array) ($nlData['segments'] ?? []);
$nlBase = ['section' => 'subscribers', 'q' => $nlF['search'] ?? '', 'status' => $nlF['status'] ?? '', 'segment' => $nlF['segment'] ?? ''];
$nlFiltered = ($nlF['search'] ?? '') !== '' || ($nlF['status'] ?? '') !== '' || ($nlF['segment'] ?? '') !== '';
?>
<form method="get" action="/admin/newsletter" class="card mb-4" role="search">
    <input type="hidden" name="section" value="subscribers">
    <div class="card-body row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label" for="nlQ"><?= $nlT('newsletter.admin.filter_search') ?></label><input type="search" class="form-control" id="nlQ" name="q" maxlength="120" value="<?= $nlE($nlF['search'] ?? '') ?>"></div>
        <div class="col-sm-6 col-md-3"><label class="form-label" for="nlStatus"><?= $nlT('newsletter.admin.col_status') ?></label>
            <select class="form-select" id="nlStatus" name="status"><option value=""><?= $nlT('newsletter.admin.filter_all') ?></option>
                <?php foreach (\CMS\Services\Newsletter\NewsletterRepository::SUBSCRIBER_STATUSES as $nlS): ?><option value="<?= $nlE($nlS) ?>"<?= ($nlF['status'] ?? '') === $nlS ? ' selected' : '' ?>><?= $nlT('newsletter.status.' . $nlS) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-sm-6 col-md-3"><label class="form-label" for="nlSegment"><?= $nlT('newsletter.admin.col_segment') ?></label>
            <select class="form-select" id="nlSegment" name="segment"><option value=""><?= $nlT('newsletter.admin.filter_all') ?></option>
                <?php foreach ($nlSegments as $nlSlug => $nlL): ?><option value="<?= $nlE($nlSlug) ?>"<?= ($nlF['segment'] ?? '') === $nlSlug ? ' selected' : '' ?>><?= $nlE($nlL['de']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-primary flex-fill"><?= $nlT('newsletter.admin.filter_apply') ?></button>
            <?php if ($nlFiltered): ?><a class="btn btn-icon" href="<?= $nlE(NewsletterModule::url(['section' => 'subscribers'])) ?>" title="<?= $nlT('newsletter.admin.filter_reset') ?>" aria-label="<?= $nlT('newsletter.admin.filter_reset') ?>"><i class="ti ti-x" aria-hidden="true"></i></a><?php endif; ?>
        </div>
    </div>
</form>

<form method="post" action="<?= $nlE(NewsletterModule::url(['section' => 'subscribers'])) ?>" class="card mb-4" data-nl-bulk data-confirm-title="<?= $nlT('newsletter.admin.bulk_label') ?>" data-confirm-message="<?= $nlT('newsletter.admin.bulk_confirm') ?>" data-confirm-text="<?= $nlT('newsletter.admin.bulk_apply') ?>" data-confirm-class="btn-primary">
    <?= $nlCsrf ?>
    <input type="hidden" name="action" value="bulk_subscribers">
    <div class="card-header flex-wrap gap-2">
        <h3 class="card-title"><?= $nlT('newsletter.admin.subscribers_count', $nlNumber((int) ($nlData['total'] ?? 0))) ?></h3>
        <div class="card-actions d-flex flex-wrap gap-2">
            <a class="btn" href="<?= $nlE(NewsletterModule::url($nlBase + ['export' => 'csv'])) ?>"><i class="ti ti-download me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.export_csv') ?></a>
            <a class="btn btn-primary" href="<?= $nlE(NewsletterModule::url(['section' => 'subscriber'])) ?>"><i class="ti ti-plus me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.new_subscriber') ?></a>
        </div>
    </div>
    <?php if ($nlRows === []): ?>
        <div class="card-body text-secondary"><?= $nlT($nlFiltered ? 'newsletter.admin.empty_filtered' : 'newsletter.admin.subscribers_empty') ?></div>
    <?php else: ?>
        <div class="table-responsive"><table class="table table-vcenter card-table">
            <thead><tr>
                <th class="w-1"><input class="form-check-input" type="checkbox" data-nl-check-all aria-label="<?= $nlT('newsletter.admin.select_all') ?>"></th>
                <th><?= $nlT('newsletter.admin.col_email') ?></th><th><?= $nlT('newsletter.admin.col_status') ?></th><th><?= $nlT('newsletter.admin.col_segment') ?></th>
                <th><?= $nlT('newsletter.admin.col_source') ?></th><th><?= $nlT('newsletter.admin.col_date') ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($nlRows as $nlRow): ?>
                <tr>
                    <td><input class="form-check-input" type="checkbox" name="ids[]" value="<?= (int) $nlRow['id'] ?>" aria-label="<?= $nlE($nlRow['email']) ?>"></td>
                    <td><a href="<?= $nlE(NewsletterModule::url(['section' => 'subscriber', 'id' => (int) $nlRow['id']])) ?>"><?= $nlE($nlRow['email']) ?></a>
                        <?php $nlName = trim($nlRow['first_name'] . ' ' . $nlRow['last_name']); if ($nlName !== ''): ?><div class="small text-secondary"><?= $nlE($nlName) ?></div><?php endif; ?></td>
                    <td><span class="badge <?= $nlE($nlStatusBadge[$nlRow['status']] ?? '') ?>"><?= $nlT('newsletter.status.' . $nlRow['status']) ?></span></td>
                    <td><?= $nlE($nlSegments[$nlRow['segment_slug']]['de'] ?? $nlRow['segment_slug']) ?> <span class="text-secondary small"><?= $nlE(strtoupper((string) ($nlRow['locale'] ?? 'de'))) ?></span></td>
                    <td class="text-secondary"><?= $nlT('newsletter.source.' . (in_array($nlRow['source'], \CMS\Services\Newsletter\NewsletterRepository::SOURCES, true) ? $nlRow['source'] : 'admin')) ?></td>
                    <td class="text-secondary text-nowrap"><?= $nlE($nlDate($nlRow['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="card-footer d-flex flex-wrap align-items-center gap-2">
            <label class="visually-hidden" for="nlBulk"><?= $nlT('newsletter.admin.bulk_label') ?></label>
            <select class="form-select w-auto" id="nlBulk" name="bulk_action">
                <option value=""><?= $nlT('newsletter.admin.bulk_label') ?></option>
                <option value="activate"><?= $nlT('newsletter.admin.bulk_activate') ?></option>
                <option value="unsubscribe"><?= $nlT('newsletter.admin.bulk_unsubscribe') ?></option>
                <option value="delete"><?= $nlT('newsletter.admin.bulk_delete') ?></option>
            </select>
            <button type="submit" class="btn"><?= $nlT('newsletter.admin.bulk_apply') ?></button>
            <?php if ($nlPages > 1): ?>
                <ul class="pagination m-0 ms-auto">
                    <li class="page-item<?= $nlPage <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= $nlE(NewsletterModule::url($nlBase + ['page' => $nlPage - 1])) ?>"><?= $nlT('newsletter.admin.prev') ?></a></li>
                    <li class="page-item disabled"><span class="page-link"><?= $nlT('newsletter.admin.page_of', $nlPage, $nlPages) ?></span></li>
                    <li class="page-item<?= $nlPage >= $nlPages ? ' disabled' : '' ?>"><a class="page-link" href="<?= $nlE(NewsletterModule::url($nlBase + ['page' => $nlPage + 1])) ?>"><?= $nlT('newsletter.admin.next') ?></a></li>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</form>

<div class="row g-4">
    <div class="col-lg-7">
        <form method="post" action="<?= $nlE(NewsletterModule::url(['section' => 'subscribers'])) ?>" enctype="multipart/form-data" class="card">
            <?= $nlCsrf ?>
            <input type="hidden" name="action" value="import_subscribers">
            <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.import_title') ?></h3></div>
            <div class="card-body">
                <p class="text-secondary"><?= $nlT('newsletter.admin.import_hint') ?></p>
                <div class="mb-3"><label class="form-label" for="nlCsvFile"><?= $nlT('newsletter.admin.import_file') ?></label><input type="file" class="form-control" id="nlCsvFile" name="csv_file" accept=".csv,text/csv,text/plain"></div>
                <div class="mb-3"><label class="form-label" for="nlCsv"><?= $nlT('newsletter.admin.import_text') ?></label><textarea class="form-control font-monospace" id="nlCsv" name="csv" rows="4" placeholder="max@example.org;Max;Muster;general"></textarea></div>
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label" for="nlImportStatus"><?= $nlT('newsletter.admin.col_status') ?></label>
                        <select class="form-select" id="nlImportStatus" name="import_status"><option value="pending"><?= $nlT('newsletter.admin.import_pending') ?></option><option value="active"><?= $nlT('newsletter.admin.import_active') ?></option></select></div>
                    <div class="col-sm-6"><label class="form-label" for="nlImportSegment"><?= $nlT('newsletter.admin.col_segment') ?></label>
                        <select class="form-select" id="nlImportSegment" name="segment"><?php foreach ($nlSegments as $nlSlug => $nlL): ?><option value="<?= $nlE($nlSlug) ?>"><?= $nlE($nlL['de']) ?></option><?php endforeach; ?></select></div>
                </div>
                <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="consent_confirmed" value="1" required><span class="form-check-label"><?= $nlT('newsletter.admin.import_consent') ?></span></label>
            </div>
            <div class="card-footer d-flex"><button type="submit" class="btn btn-primary ms-auto"><i class="ti ti-file-import me-1" aria-hidden="true"></i><?= $nlT('newsletter.admin.import_button') ?></button></div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><?= $nlT('newsletter.admin.exports_title') ?></h3></div>
            <?php if (empty($nlData['exports'])): ?>
                <div class="card-body text-secondary"><?= $nlT('newsletter.admin.exports_empty') ?></div>
            <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ((array) $nlData['exports'] as $nlFile): ?>
                        <div class="list-group-item d-flex align-items-center gap-2">
                            <a class="text-truncate flex-fill" href="<?= $nlE(NewsletterModule::url(['privacy_export' => $nlFile['name']])) ?>"><?= $nlE($nlFile['name']) ?></a>
                            <form method="post" action="<?= $nlE(NewsletterModule::url(['section' => 'subscribers'])) ?>"><?= $nlCsrf ?><input type="hidden" name="action" value="delete_export"><input type="hidden" name="file" value="<?= $nlE($nlFile['name']) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger btn-icon" title="<?= $nlT('newsletter.admin.action_delete') ?>" aria-label="<?= $nlT('newsletter.admin.action_delete') ?>"><i class="ti ti-trash" aria-hidden="true"></i></button></form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="card-footer small text-secondary"><?= $nlT('newsletter.admin.exports_hint') ?></div>
        </div>
    </div>
</div>
