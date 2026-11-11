<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Suchindex – Tab „Status“: Neuaufbau und Indexstand je Sprache
 *
 * @var array<string, mixed> $siData
 * @var callable $siE
 * @var callable $siT
 * @var callable $siDate
 * @var callable $siBytes
 * @var callable $siNumber
 */

$siAvailable = !empty($siData['available']);
$siStatus = is_array($siData['status'] ?? null) ? $siData['status'] : [];
$siActive = is_array($siData['active_job'] ?? null) ? $siData['active_job'] : null;
$siLatest = is_array($siData['latest_job'] ?? null) ? $siData['latest_job'] : null;
$siApi = is_array($siData['api'] ?? null) ? $siData['api'] : [];
$siJobStatusKeys = [
    'pending' => 'search_index.job_status_pending',
    'running' => 'search_index.job_status_running',
    'done' => 'search_index.job_status_done',
    'failed' => 'search_index.job_status_failed',
    'cancelled' => 'search_index.job_status_cancelled',
];
$siRunnerLabels = [];
foreach (['pending', 'running', 'done', 'failed', 'cancelled', 'paused', 'busy', 'pause', 'resume', 'cancel', 'cancelTitle', 'cancelMessage', 'networkError', 'errors', 'progress'] as $siLabel) {
    $siRunnerLabels[$siLabel] = (string) __('search_index.runner_' . strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1_$2', $siLabel)));
}
$siConfig = [
    'endpoint' => (string) ($siApi['endpoint'] ?? ''),
    'token' => (string) ($siApi['token'] ?? ''),
    'activeJob' => $siActive,
    'runnerLabels' => $siRunnerLabels,
    'labels' => [
        'start' => (string) __('search_index.rebuild_start'),
        'resume' => (string) __('search_index.rebuild_resume'),
        'resumed' => (string) __('search_index.rebuild_already_running'),
        'done' => (string) __('search_index.rebuild_done'),
        'error' => (string) __('search_index.error_internal'),
        'cancelTitle' => (string) __('search_index.runner_cancel_title'),
        'cancelMessage' => (string) __('search_index.runner_cancel_message'),
        'cancel' => (string) __('search_index.runner_cancel'),
    ],
];
?>

<?php if (!$siAvailable): ?>
    <div class="alert alert-warning" role="alert">
        <?= $siT('search_index.unavailable') ?>
        <?php if ((string) ($siData['unavailable_reason'] ?? '') !== ''): ?>
            <div class="small text-secondary mt-1"><?= $siE($siData['unavailable_reason']) ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!empty($siData['rebuild_recommended']) && $siActive === null): ?>
    <div class="alert alert-info" role="status"><?= $siT('search_index.rebuild_recommended') ?></div>
<?php endif; ?>

<?php if ($siAvailable): ?>
<div class="card mb-4" data-search-index="<?= $siE(json_encode($siConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <div class="card-header">
        <h3 class="card-title"><?= $siT('search_index.rebuild_title') ?></h3>
    </div>
    <div class="card-body">
        <p class="text-secondary"><?= $siT('search_index.rebuild_intro') ?></p>

        <?php if ($siActive !== null): ?>
            <div class="alert alert-warning" role="status" data-search-index-notice>
                <?= $siT('search_index.rebuild_interrupted', $siNumber((int) $siActive['processed']), $siNumber((int) $siActive['total']), $siDate((string) $siActive['created_at'])) ?>
            </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" data-search-index-start>
                <?= $siT($siActive !== null ? 'search_index.rebuild_resume' : 'search_index.rebuild_start') ?>
            </button>
            <?php if ($siActive !== null): ?>
                <button type="button" class="btn btn-outline-danger" data-search-index-cancel><?= $siT('search_index.runner_cancel') ?></button>
            <?php endif; ?>
        </div>

        <div class="mt-3" data-search-index-progress hidden></div>

        <?php if ($siLatest !== null && $siActive === null): ?>
            <p class="small text-secondary mt-3 mb-0" data-search-index-latest>
                <?= $siT(
                    'search_index.last_run',
                    (string) __($siJobStatusKeys[(string) $siLatest['status']] ?? 'search_index.job_status_pending'),
                    $siDate((string) ($siLatest['finished_at'] !== '' ? $siLatest['finished_at'] : $siLatest['created_at'])),
                    $siNumber((int) $siLatest['processed']),
                    $siNumber((int) $siLatest['total'])
                ) ?>
                <?php if ((int) $siLatest['failed'] > 0): ?>
                    · <span class="text-danger"><?= $siT('search_index.last_run_failed', $siNumber((int) $siLatest['failed'])) ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><?= $siT('search_index.status_title') ?></h3>
    </div>
    <div class="table-responsive">
        <table class="table table-vcenter card-table">
            <thead>
                <tr>
                    <th><?= $siT('search_index.col_locale') ?></th>
                    <th class="text-end"><?= $siT('search_index.col_documents') ?></th>
                    <th class="text-end"><?= $siT('search_index.col_expected') ?></th>
                    <th><?= $siT('search_index.col_built_at') ?></th>
                    <th class="text-end"><?= $siT('search_index.col_size') ?></th>
                    <th><?= $siT('search_index.col_state') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($siStatus as $siRow): ?>
                    <?php
                    $siRowState = $siRow['missing'] !== []
                        ? ['warning', 'search_index.state_missing']
                        : ((int) $siRow['documents'] !== (int) $siRow['expected'] ? ['info', 'search_index.state_diverging'] : ['success', 'search_index.state_current']);
                    ?>
                    <tr class="table-light">
                        <td><strong><?= $siE(strtoupper((string) $siRow['locale'])) ?></strong></td>
                        <td class="text-end"><strong><?= $siNumber((int) $siRow['documents']) ?></strong></td>
                        <td class="text-end"><?= $siNumber((int) $siRow['expected']) ?></td>
                        <td><?= $siDate((string) $siRow['built_at']) ?></td>
                        <td class="text-end"><?= $siBytes((int) $siRow['size']) ?></td>
                        <td><span class="badge bg-<?= $siRowState[0] ?>-lt"><?= $siT($siRowState[1]) ?></span></td>
                    </tr>
                    <?php foreach ($siRow['types'] as $siType): ?>
                        <?php
                        $siTypeState = !$siType['exists']
                            ? ['warning', 'search_index.state_missing']
                            : ((int) $siType['documents'] !== (int) $siType['expected'] ? ['info', 'search_index.state_diverging'] : ['success', 'search_index.state_current']);
                        ?>
                        <tr>
                            <td class="ps-4 text-secondary"><?= $siE($siType['label']) ?></td>
                            <td class="text-end"><?= $siType['exists'] ? $siNumber((int) $siType['documents']) : '–' ?></td>
                            <td class="text-end"><?= $siNumber((int) $siType['expected']) ?></td>
                            <td><?= $siDate((string) $siType['built_at']) ?></td>
                            <td class="text-end"><?= $siType['exists'] ? $siBytes((int) $siType['size']) : '–' ?></td>
                            <td><span class="badge bg-<?= $siTypeState[0] ?>-lt"><?= $siT($siTypeState[1]) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-secondary"><?= $siT('search_index.status_hint') ?></div>
</div>
<?php endif; ?>
