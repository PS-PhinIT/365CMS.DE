<?php
declare(strict_types=1);

/**
 * @var array<string, mixed> $data types, token, manifest, preview, users, strategies, max_mb, api, active_export, active_import
 * @var string $csrfToken
 */

if (!defined('ABSPATH')) {
    exit;
}

$exEsc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$exT = static fn (string $key, string|int ...$args): string => htmlspecialchars($args !== [] ? vsprintf((string) __($key), $args) : (string) __($key), ENT_QUOTES, 'UTF-8');
$exTypes = is_array($data['types'] ?? null) ? $data['types'] : [];
$exManifest = is_array($data['manifest'] ?? null) ? $data['manifest'] : null;
$exPreview = is_array($data['preview'] ?? null) ? $data['preview'] : [];
$exConfig = [
    'endpoint' => (string) ($data['api']['endpoint'] ?? ''),
    'token' => (string) ($data['api']['token'] ?? ''),
    'activeExport' => $data['active_export'] ?? null,
    'activeImport' => $data['active_import'] ?? null,
    'importToken' => (string) ($data['token'] ?? ''),
    'labels' => [
        'exportDone' => (string) __('exchange.export.done'),
        'download' => (string) __('exchange.export.download'),
        'importDone' => (string) __('exchange.import.done'),
        'error' => (string) __('exchange.error.internal'),
        'statusCreated' => (string) __('exchange.status.created'),
        'statusUpdated' => (string) __('exchange.status.updated'),
        'statusRenamed' => (string) __('exchange.status.renamed'),
        'statusSkipped' => (string) __('exchange.status.skipped'),
        'statusFailed' => (string) __('exchange.status.failed'),
    ],
    'typeLabels' => $exTypes,
];
?>
<div class="page-header d-print-none admin-redesign-header">
    <div class="container-xl">
        <div class="content-listing-header">
            <div>
                <div class="page-pretitle"><?php echo $exT('exchange.pretitle'); ?></div>
                <h2 class="page-title mb-1"><?php echo $exT('exchange.title'); ?></h2>
                <div class="content-listing-header__meta"><span><?php echo $exT('exchange.intro'); ?></span></div>
            </div>
        </div>
    </div>
</div>

<div class="page-body" id="content-exchange-root" data-content-exchange="<?php echo $exEsc(json_encode($exConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?>">
    <div class="container-xl">
        <?php if (!empty($alert)): ?>
            <?php $alertData = $alert; $alertMarginClass = 'mb-4'; require __DIR__ . '/../partials/flash-alert.php'; ?>
        <?php endif; ?>
        <?php if (!empty($data['invalid_token'])): ?>
            <div class="alert alert-warning"><?php echo $exT('exchange.error.staging_missing'); ?></div>
        <?php endif; ?>

        <div class="row row-cards">
            <div class="col-lg-6">
                <form class="card h-100" id="exchange-export-form" data-exchange-export>
                    <div class="card-header"><h3 class="card-title"><?php echo $exT('exchange.export.title'); ?></h3></div>
                    <div class="card-body">
                        <fieldset class="mb-3">
                            <legend class="form-label"><?php echo $exT('exchange.export.types'); ?></legend>
                            <?php foreach ($exTypes as $exType => $exLabel): ?>
                                <label class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="types[]" value="<?php echo $exEsc($exType); ?>" checked>
                                    <span class="form-check-label"><?php echo $exEsc($exLabel); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <fieldset class="mb-3">
                            <legend class="form-label"><?php echo $exT('exchange.export.statuses'); ?></legend>
                            <?php foreach (['published', 'draft', 'private', 'scheduled'] as $exStatus): ?>
                                <label class="form-check form-check-inline">
                                    <input class="form-check-input" type="checkbox" name="statuses[]" value="<?php echo $exEsc($exStatus); ?>" checked>
                                    <span class="form-check-label"><?php echo $exT('content_changes.status.' . $exStatus); ?></span>
                                </label>
                            <?php endforeach; ?>
                            <div class="form-hint"><?php echo $exT('exchange.export.statuses_hint'); ?></div>
                        </fieldset>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label" for="exDateFrom"><?php echo $exT('exchange.export.date_from'); ?></label>
                                <input class="form-control" type="date" id="exDateFrom" name="date_from">
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="exDateTo"><?php echo $exT('exchange.export.date_to'); ?></label>
                                <input class="form-control" type="date" id="exDateTo" name="date_to">
                            </div>
                        </div>
                        <label class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="include_media_files" value="1" checked>
                            <span class="form-check-label"><?php echo $exT('exchange.export.media_files'); ?></span>
                        </label>
                        <div class="mt-3" data-exchange-export-progress></div>
                        <div class="mt-2" data-exchange-export-result hidden></div>
                    </div>
                    <div class="card-footer text-end">
                        <button type="submit" class="btn btn-primary" data-exchange-export-start><?php echo $exT('exchange.export.start'); ?></button>
                    </div>
                </form>
            </div>

            <div class="col-lg-6" id="import">
                <?php if ($exManifest === null): ?>
                    <form class="card h-100" method="post" action="/admin/content-exchange" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $exEsc($csrfToken); ?>">
                        <input type="hidden" name="action" value="upload">
                        <div class="card-header"><h3 class="card-title"><?php echo $exT('exchange.import.title'); ?></h3></div>
                        <div class="card-body">
                            <p class="text-secondary"><?php echo $exT('exchange.import.intro'); ?></p>
                            <label class="form-label required" for="exArchive"><?php echo $exT('exchange.import.file', (int) ($data['max_mb'] ?? 256)); ?></label>
                            <input class="form-control" type="file" id="exArchive" name="archive" accept=".zip,application/zip" required>
                            <div class="form-hint"><?php echo $exT('exchange.import.file_hint'); ?></div>
                        </div>
                        <div class="card-footer text-end">
                            <button type="submit" class="btn btn-primary"><?php echo $exT('exchange.import.upload'); ?></button>
                        </div>
                    </form>
                <?php else: ?>
                    <form class="card h-100" data-exchange-import>
                        <div class="card-header">
                            <h3 class="card-title"><?php echo $exT('exchange.import.preview_title'); ?></h3>
                        </div>
                        <div class="card-body">
                            <p class="small text-secondary mb-2"><?php echo $exT('exchange.import.source', (string) ($exManifest['site_url'] ?? '?'), (string) ($exManifest['cms_version'] ?? '?'), (string) ($exManifest['schema_version'] ?? '?'), (string) date('d.m.Y H:i', (int) strtotime((string) ($exManifest['created_at'] ?? 'now')))); ?></p>
                            <?php if ($exPreview === []): ?>
                                <div class="alert alert-warning"><?php echo $exT('exchange.import.nothing'); ?></div>
                            <?php else: ?>
                                <div class="table-responsive mb-3">
                                    <table class="table table-sm table-vcenter">
                                        <thead><tr><th></th><th><?php echo $exT('exchange.import.col_type'); ?></th><th class="text-end"><?php echo $exT('exchange.import.col_new'); ?></th><th class="text-end"><?php echo $exT('exchange.import.col_conflict'); ?></th><th class="text-end"><?php echo $exT('exchange.import.col_invalid'); ?></th></tr></thead>
                                        <tbody>
                                            <?php foreach ($exPreview as $exType => $exRow): ?>
                                                <tr>
                                                    <td><input class="form-check-input" type="checkbox" name="types[]" value="<?php echo $exEsc($exType); ?>"<?php echo (int) $exRow['total'] > 0 ? ' checked' : ' disabled'; ?> aria-label="<?php echo $exEsc($exRow['label']); ?>"></td>
                                                    <td><?php echo $exEsc($exRow['label']); ?> <span class="text-secondary">(<?php echo (int) $exRow['total']; ?>)</span></td>
                                                    <td class="text-end"><?php echo (int) $exRow['create']; ?></td>
                                                    <td class="text-end<?php echo (int) $exRow['conflict'] > 0 ? ' text-warning fw-bold' : ''; ?>"><?php echo (int) $exRow['conflict']; ?></td>
                                                    <td class="text-end<?php echo (int) $exRow['invalid'] > 0 ? ' text-danger fw-bold' : ''; ?>"><?php echo (int) $exRow['invalid']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php $exSamples = array_merge(...array_values(array_map(static fn (array $row): array => array_map(static fn (array $sample): array => $sample + ['type' => $row['label']], $row['samples']), $exPreview))); ?>
                                <?php if ($exSamples !== []): ?>
                                    <details class="mb-3">
                                        <summary><?php echo $exT('exchange.import.samples', count($exSamples)); ?></summary>
                                        <ul class="small mt-2 mb-0">
                                            <?php foreach (array_slice($exSamples, 0, 40) as $exSample): ?>
                                                <li><span class="<?php echo $exSample['action'] === 'invalid' ? 'text-danger' : 'text-warning'; ?>"><?php echo $exEsc($exSample['type']); ?>:</span> <?php echo $exEsc($exSample['label']); ?> – <?php echo $exEsc($exSample['message']); ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label class="form-label" for="exStrategy"><?php echo $exT('exchange.import.strategy'); ?></label>
                                    <select class="form-select" id="exStrategy" name="strategy">
                                        <?php foreach ($data['strategies'] ?? [] as $exStrategy): ?>
                                            <option value="<?php echo $exEsc($exStrategy); ?>"><?php echo $exT('exchange.strategy.' . $exStrategy); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <label class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="draft" value="1" checked>
                                    <span class="form-check-label"><?php echo $exT('exchange.import.draft'); ?></span>
                                </label>
                                <?php if (!empty($exManifest['authors'])): ?>
                                    <fieldset class="mb-2">
                                        <legend class="form-label"><?php echo $exT('exchange.import.authors'); ?></legend>
                                        <?php foreach ($exManifest['authors'] as $exAuthor): ?>
                                            <div class="row g-2 align-items-center mb-1">
                                                <label class="col-sm-5 col-form-label" for="exAuthor<?php echo (int) $exAuthor['id']; ?>"><?php echo $exEsc($exAuthor['name'] ?? ('#' . $exAuthor['id'])); ?></label>
                                                <div class="col-sm-7">
                                                    <select class="form-select form-select-sm" id="exAuthor<?php echo (int) $exAuthor['id']; ?>" name="author_map[<?php echo (int) $exAuthor['id']; ?>]">
                                                        <option value=""><?php echo $exT('exchange.import.author_me'); ?></option>
                                                        <?php foreach ($data['users'] ?? [] as $exUser): ?>
                                                            <option value="<?php echo (int) $exUser['id']; ?>"<?php echo strcasecmp((string) $exUser['name'], (string) ($exAuthor['name'] ?? '')) === 0 ? ' selected' : ''; ?>><?php echo $exEsc($exUser['name']); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </fieldset>
                                <?php endif; ?>
                            <?php endif; ?>
                            <div class="mt-3" data-exchange-import-progress></div>
                            <div class="mt-2" data-exchange-import-result hidden></div>
                        </div>
                        <div class="card-footer d-flex justify-content-between">
                            <a class="btn btn-link" href="/admin/content-exchange"><?php echo $exT('exchange.import.discard'); ?></a>
                            <?php if ($exPreview !== []): ?>
                                <button type="submit" class="btn btn-primary" data-exchange-import-start><?php echo $exT('exchange.import.start'); ?></button>
                            <?php endif; ?>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
